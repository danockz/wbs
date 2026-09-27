<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use WBS\Shared\Support\Result;

/**
 * Shared event field validation (SRS FR-EVT-001) — the ONE place create and
 * update agree on what a well-formed event is.
 *
 * PURE + DB-FREE by design: it only inspects the caller-supplied array, so it is
 * cheap (no query, safe on the hot path), trivially unit-testable, and identical
 * for both the create and the edit path — closing the gap where creation only
 * checked title + start while nothing guarded the schema's enums, the time range,
 * a non-negative capacity, or a virtual event's join URL.
 *
 * On the first problem it returns a Result::fail whose `message` is a STABLE
 * lang key under `Events.validate.*` (English-fallback translated by the caller /
 * form) and whose `code` is a stable machine identifier; on success it returns
 * null. Enum sets mirror the `events` table columns exactly.
 */
final class EventValidator
{
    /** Allowed `mode` values (mirrors events.mode). */
    public const MODES = ['physical', 'online', 'hybrid'];

    /** Allowed `registration_policy` values (mirrors events.registration_policy). */
    public const REGISTRATION_POLICIES = ['open', 'invite', 'closed'];

    /** Allowed `attendance_policy` values (mirrors events.attendance_policy). */
    public const ATTENDANCE_POLICIES = ['checkin', 'streaming', 'manual'];

    /** Modes that imply an online presence and therefore need a join/access URL. */
    public const VIRTUAL_MODES = ['online', 'hybrid'];

    /**
     * Validate an event payload. Returns null when valid, else the first failure.
     *
     * Only keys that are PRESENT are enum/shape-checked (so a partial update that
     * omits a field leaves it untouched) — except title/starts_at, which are
     * required unless `$partial` is true. `mode` defaults to the schema default
     * (physical) for the virtual-URL check only when the key is absent, matching
     * how create() fills it.
     *
     * @param array<string,mixed> $data
     */
    public static function validate(array $data, bool $partial = false): ?Result
    {
        // ---- Required fields (skipped for a partial/patch update) -----------
        if (! $partial || array_key_exists('title', $data)) {
            if (trim((string) ($data['title'] ?? '')) === '') {
                return Result::fail('TITLE_REQUIRED', 'Events.validate.titleRequired', 422, ['field' => 'title']);
            }
        }
        if (! $partial || array_key_exists('starts_at', $data)) {
            if (trim((string) ($data['starts_at'] ?? '')) === '') {
                return Result::fail('START_REQUIRED', 'Events.validate.startRequired', 422, ['field' => 'starts_at']);
            }
        }

        // ---- End must not precede start -------------------------------------
        $start = trim((string) ($data['starts_at'] ?? ''));
        $end   = trim((string) ($data['ends_at'] ?? ''));
        if ($start !== '' && $end !== '') {
            $ts = strtotime($start);
            $te = strtotime($end);
            if ($ts !== false && $te !== false && $te < $ts) {
                return Result::fail('BAD_TIME_RANGE', 'Events.validate.endsBeforeStart', 422, ['field' => 'ends_at']);
            }
        }

        // ---- Enum guards (only when the key is present) ---------------------
        if (array_key_exists('mode', $data) && (string) $data['mode'] !== ''
            && ! in_array((string) $data['mode'], self::MODES, true)) {
            return Result::fail('BAD_MODE', 'Events.validate.badMode', 422, ['field' => 'mode']);
        }
        if (array_key_exists('registration_policy', $data) && (string) $data['registration_policy'] !== ''
            && ! in_array((string) $data['registration_policy'], self::REGISTRATION_POLICIES, true)) {
            return Result::fail('BAD_REG_POLICY', 'Events.validate.badRegPolicy', 422, ['field' => 'registration_policy']);
        }
        if (array_key_exists('attendance_policy', $data) && (string) $data['attendance_policy'] !== ''
            && ! in_array((string) $data['attendance_policy'], self::ATTENDANCE_POLICIES, true)) {
            return Result::fail('BAD_ATT_POLICY', 'Events.validate.badAttPolicy', 422, ['field' => 'attendance_policy']);
        }

        // ---- Capacity: a non-negative integer when supplied ----------------
        if (array_key_exists('capacity', $data) && $data['capacity'] !== null && (string) $data['capacity'] !== '') {
            $cap = $data['capacity'];
            if (! is_numeric($cap) || (int) $cap != $cap || (int) $cap < 0) {
                return Result::fail('BAD_CAPACITY', 'Events.validate.badCapacity', 422, ['field' => 'capacity']);
            }
        }

        // ---- A virtual/hybrid event needs a join URL -----------------------
        // Use the effective mode: the supplied value, or the schema default when
        // the key is absent on a full create (partial updates that omit mode do
        // not trigger this — the stored mode is untouched).
        $mode = null;
        if (array_key_exists('mode', $data) && (string) $data['mode'] !== '') {
            $mode = (string) $data['mode'];
        } elseif (! $partial) {
            $mode = 'physical';
        }
        if ($mode !== null && in_array($mode, self::VIRTUAL_MODES, true)) {
            if (trim((string) ($data['access_url'] ?? '')) === '') {
                return Result::fail('ACCESS_URL_REQUIRED', 'Events.validate.accessUrlRequired', 422, ['field' => 'access_url']);
            }
        }

        return null;
    }
}
