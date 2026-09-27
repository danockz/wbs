<?php

declare(strict_types=1);

namespace WBS\Announcements\Controllers;

use WBS\Announcements\Config\Services as AnnouncementServices;
use WBS\Announcements\Support\AnnouncementScope;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Announcements console + must-ack inbox. Composer writes are notification.send
 * inside leadership scope; publish is notification.broadcast.approve with SoD.
 */
final class AnnouncementController extends BaseController
{
    public const DASHBOARD = '/announcements';
    public const INBOX     = '/announcements/inbox';

    public function index()
    {
        $status = $this->field('status');
        $rows   = AnnouncementServices::announcements(false)->listForOrg(
            $this->orgId(),
            $status !== null && $status !== '' ? (string) $status : null,
        );
        $payload = Result::ok(['announcements' => $rows]);
        if ($this->wantsJson()) {
            return $this->respondWith($payload);
        }

        return $this->renderForm('WBS\\Announcements\\Views\\index', [
            'announcements' => $rows,
            'modes'         => AnnouncementScope::ALL,
        ]);
    }

    public function create()
    {
        $group = trim((string) ($this->field('group_id') ?? ''));
        $scope = $group !== '' ? $group : null;
        if (! $this->canManageGroupScope('notification.send', $scope)) {
            return $this->deny(Result::fail('FORBIDDEN', 'announcement.forbidden', 403));
        }
        $actor = (string) ($this->actorId() ?? '');
        $result = AnnouncementServices::announcements(false)->create($this->orgId(), $actor, $this->input());

        return $this->prg($result, self::DASHBOARD, 'createdFlash');
    }

    public function submit(string $id = '')
    {
        $row = AnnouncementServices::announcements(false)->find($this->orgId(), $id);
        if ($row === null) {
            return $this->deny(Result::notFound('announcement.not_found', 'NOT_FOUND'));
        }
        $scope = ($row['group_id'] ?? null) ? (string) $row['group_id'] : null;
        if (! $this->canManageGroupScope('notification.send', $scope)) {
            return $this->deny(Result::fail('FORBIDDEN', 'announcement.forbidden', 403));
        }

        return $this->prg(
            AnnouncementServices::announcements(false)->submit($this->orgId(), $id),
            self::DASHBOARD,
            'submittedFlash',
        );
    }

    public function approve(string $id = '')
    {
        $row = AnnouncementServices::announcements(false)->find($this->orgId(), $id);
        if ($row === null) {
            return $this->deny(Result::notFound('announcement.not_found', 'NOT_FOUND'));
        }
        $scope = ($row['group_id'] ?? null) ? (string) $row['group_id'] : null;
        if (! $this->canManageGroupScope('notification.broadcast.approve', $scope)) {
            return $this->deny(Result::fail('FORBIDDEN', 'announcement.forbidden', 403));
        }
        $actor = (string) ($this->actorId() ?? '');

        return $this->prg(
            AnnouncementServices::announcements(false)->approve($this->orgId(), $id, $actor),
            self::DASHBOARD,
            'approvedFlash',
        );
    }

    public function cancel(string $id = '')
    {
        $row = AnnouncementServices::announcements(false)->find($this->orgId(), $id);
        if ($row === null) {
            return $this->deny(Result::notFound('announcement.not_found', 'NOT_FOUND'));
        }
        $scope = ($row['group_id'] ?? null) ? (string) $row['group_id'] : null;
        if (! $this->canManageGroupScope('notification.send', $scope)) {
            return $this->deny(Result::fail('FORBIDDEN', 'announcement.forbidden', 403));
        }

        return $this->prg(
            AnnouncementServices::announcements(false)->cancel($this->orgId(), $id),
            self::DASHBOARD,
            'cancelledFlash',
        );
    }

    public function inbox()
    {
        $uid = $this->currentUserId();
        if ($uid === '') {
            return $this->respondWith(Result::fail('AUTH_REQUIRED', 'announcement.auth_required', 401));
        }
        $items = AnnouncementServices::announcements(false)->inbox($this->orgId(), $uid);
        $payload = Result::ok(['announcements' => $items, 'count' => count($items)]);
        if ($this->wantsJson()) {
            return $this->respondWith($payload);
        }

        return $this->renderForm('WBS\\Announcements\\Views\\inbox', [
            'announcements' => $items,
        ]);
    }

    public function ack(string $id = '')
    {
        $uid = $this->currentUserId();
        if ($uid === '') {
            return $this->deny(Result::fail('AUTH_REQUIRED', 'announcement.auth_required', 401), self::INBOX);
        }

        return $this->prg(
            AnnouncementServices::announcements(false)->ack($this->orgId(), $id, $uid),
            self::INBOX,
            'ackedFlash',
        );
    }

    private function deny(Result $fail, string $to = self::DASHBOARD)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($fail);
        }

        return redirect()->to($to)->with('error', $this->errText((string) $fail->message));
    }

    private function prg(Result $result, string $to, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Announcements.' . $okKey));
    }
}
