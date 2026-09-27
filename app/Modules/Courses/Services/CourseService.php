<?php

declare(strict_types=1);

namespace WBS\Courses\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Course authoring + drip-feed visibility (SRS FR-CRS-001/004).
 *
 * Drip logic reveals a lesson's unlock date/time and unmet prerequisites
 * WITHOUT leaking hidden lesson content — locked lessons return metadata only,
 * never content_ref.
 */
final class CourseService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(string $organizationId, array $data): Result
    {
        if (empty($data['title'])) {
            return Result::fail('TITLE_REQUIRED', 'course.title_required', 422);
        }
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('courses')->insert([
            'id'                => $id,
            'organization_id'   => $organizationId,
            'group_id'          => $data['group_id'] ?? null,
            'title'             => $data['title'],
            'slug'              => $data['slug'] ?? null,
            'category'          => $data['category'] ?? null,
            'description'       => $data['description'] ?? null,
            'delivery_mode'     => $data['delivery_mode'] ?? 'self_paced',
            'prerequisites'     => isset($data['prerequisites']) ? json_encode($data['prerequisites'], JSON_UNESCAPED_UNICODE) : null,
            'enrollment_policy' => $data['enrollment_policy'] ?? 'open',
            'completion_rule'   => isset($data['completion_rule']) ? json_encode($data['completion_rule'], JSON_UNESCAPED_UNICODE) : null,
            'points_rule_code'  => $data['points_rule_code'] ?? null,
            'badge_code'        => $data['badge_code'] ?? null,
            'status'            => 'draft',
            'created_at'        => $now,
        ]);

        return Result::created(['course_id' => $id, 'status' => 'draft']);
    }

    public function publish(string $courseId): Result
    {
        $this->db->table('courses')->where('id', $courseId)->update([
            'status'     => 'published',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['course_id' => $courseId, 'status' => 'published']);
    }

    /** @param array<string,mixed> $data */
    public function addLesson(string $courseId, array $data): Result
    {
        if (empty($data['title'])) {
            return Result::fail('TITLE_REQUIRED', 'lesson.title_required', 422);
        }
        $id = Uuid::v7();
        $this->db->table('lessons')->insert([
            'id'               => $id,
            'course_id'        => $courseId,
            'module_id'        => $data['module_id'] ?? null,
            'title'            => $data['title'],
            'position'         => (int) ($data['position'] ?? 0),
            'required'         => isset($data['required']) ? (int) (bool) $data['required'] : 1,
            'drip_unlock_at'   => $data['drip_unlock_at'] ?? null,
            'drip_offset_days' => $data['drip_offset_days'] ?? null,
            'content_ref'      => $data['content_ref'] ?? null,
            'created_at'       => $this->clock->nowUtcString(),
        ]);

        return Result::created(['lesson_id' => $id]);
    }

    /** Single course row, or null. */
    public function find(string $courseId): ?array
    {
        return $this->db->table('courses')->where('id', $courseId)->get()->getRowArray() ?: null;
    }

    /**
     * Courses for an organization (SRS FR-CRS-001) for the catalogue index page
     * and API. Each row includes its lesson count.
     *
     * @return list<array<string,mixed>>
     */
    public function listForOrg(string $organizationId, int $limit = 50): array
    {
        $courses = $this->db->table('courses')
            ->select('id, title, status, category, delivery_mode')
            ->where('organization_id', $organizationId)
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, min($limit, 200)))
            ->get()->getResultArray();

        foreach ($courses as &$c) {
            $c['lesson_count'] = (int) $this->db->table('lessons')
                ->where('course_id', $c['id'])->countAllResults();
        }
        unset($c);

        return $courses;
    }

    /**
     * Author/admin course overview (SRS FR-CRS-001): the course header plus its
     * lesson list with drip configuration. Unlike the learner syllabus this is a
     * structural/authoring view — it reports each lesson's drip rule and whether
     * a content object is attached, but never returns the content_ref value
     * itself, so the object id is not exposed to the browser.
     *
     * @return array<string,mixed>|null
     */
    public function overview(string $courseId): ?array
    {
        $course = $this->find($courseId);
        if ($course === null) {
            return null;
        }

        $lessons = $this->db->table('lessons')
            ->where('course_id', $courseId)->orderBy('position')->get()->getResultArray();

        $out = [];
        foreach ($lessons as $l) {
            if (! empty($l['drip_unlock_at'])) {
                $drip = 'unlocks ' . $l['drip_unlock_at'];
            } elseif ($l['drip_offset_days'] !== null) {
                $drip = '+' . (int) $l['drip_offset_days'] . ' day(s) after enrolment';
            } else {
                $drip = 'immediate';
            }

            $out[] = [
                'lesson_id'   => $l['id'],
                'title'       => $l['title'],
                'position'    => (int) $l['position'],
                'required'    => (bool) $l['required'],
                'drip'        => $drip,
                'has_content' => ! empty($l['content_ref']),
            ];
        }

        return [
            'course_id'     => $course['id'],
            'title'         => $course['title'],
            'status'        => $course['status'] ?? 'draft',
            'category'      => $course['category'] ?? null,
            'delivery_mode' => $course['delivery_mode'] ?? null,
            'description'   => $course['description'] ?? null,
            'lesson_count'  => count($out),
            'required_count' => count(array_filter($out, static fn ($l) => $l['required'])),
            'lessons'       => $out,
        ];
    }

    /**
     * Learner view of the syllabus with per-lesson lock state. Locked lessons
     * expose their unlock time but NOT their content (FR-CRS-004).
     *
     * @return list<array<string,mixed>>
     */
    public function syllabusFor(string $courseId, string $enrolledAt): array
    {
        $lessons = $this->db->table('lessons')->where('course_id', $courseId)->orderBy('position')->get()->getResultArray();
        $now     = $this->clock->now();
        $base    = new DateTimeImmutable($enrolledAt);

        $out = [];
        foreach ($lessons as $l) {
            $unlockAt = null;
            if (! empty($l['drip_unlock_at'])) {
                $unlockAt = new DateTimeImmutable($l['drip_unlock_at']);
            } elseif ($l['drip_offset_days'] !== null) {
                $unlockAt = $base->modify('+' . (int) $l['drip_offset_days'] . ' days');
            }
            $locked = $unlockAt !== null && $now < $unlockAt;

            $out[] = [
                'lesson_id'   => $l['id'],
                'title'       => $l['title'],
                'position'    => (int) $l['position'],
                'required'    => (bool) $l['required'],
                'locked'      => $locked,
                'unlock_at'   => $unlockAt?->format('Y-m-d H:i:s'),
                // Content only when unlocked — never leak locked content.
                'content_ref' => $locked ? null : $l['content_ref'],
            ];
        }

        return $out;
    }
}
