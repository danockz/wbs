<?php

declare(strict_types=1);

namespace WBS\Notifications\Controllers;

use WBS\Groups\Config\Services as GroupServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Notification template CRUD — the content the system actually sends.
 *
 * Gated by `provider.configure` (same admins who own notification credentials).
 * Writes are scoped to groups the actor can manage; org-level rows (group_id
 * empty) require org-wide configure.
 */
final class TemplateController extends BaseController
{
    public const DASHBOARD = '/notifications/templates';

    public function index()
    {
        $org   = $this->orgId();
        $group = trim((string) ($this->field('group') ?? ''));
        $group = $group !== '' ? $group : null;
        if ($group !== null && ! $this->canManageGroupScope('provider.configure', $group)) {
            return $this->respondWith(Result::fail('FORBIDDEN', 'notification.template_forbidden', 403));
        }
        if ($group === null && ! $this->canManageGroupScope('provider.configure', null)) {
            // Fall back to the first manageable group rather than leaking org rows.
            $groups = $this->groupsInScope();
            $group  = (string) ($groups[0]['id'] ?? '');
            $group  = $group !== '' ? $group : null;
            if ($group === null) {
                return $this->respondWith(Result::fail('FORBIDDEN', 'notification.template_forbidden', 403));
            }
        }
        $rows = NotificationServices::notificationTemplates(false)->listFor($org, $group);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['templates' => $rows, 'group_id' => $group, 'count' => count($rows)]));
        }

        return $this->renderForm('WBS\\Notifications\\Views\\templates', [
            'templates' => $rows,
            'group_id'  => $group,
            'groups'    => $this->groupsInScope(),
            'keys'      => $this->knownKeys(),
        ]);
    }

    public function create()
    {
        $in    = $this->input();
        $group = trim((string) ($in['group_id'] ?? ''));
        $scope = $group !== '' ? $group : null;
        if (! $this->canManageGroupScope('provider.configure', $scope)) {
            return $this->deny();
        }
        $result = NotificationServices::notificationTemplates(false)->create($this->orgId(), $in);

        return $this->prg($result, 'templateCreatedFlash');
    }

    public function edit(string $templateId = '')
    {
        $row = NotificationServices::notificationTemplates(false)->find($this->orgId(), $templateId);
        if ($row === null) {
            return $this->respondWith(Result::notFound('notification.template_not_found', 'TEMPLATE_NOT_FOUND'));
        }
        $scope = ($row['group_id'] ?? null) !== null && (string) $row['group_id'] !== ''
            ? (string) $row['group_id'] : null;
        if (! $this->canManageGroupScope('provider.configure', $scope)) {
            return $this->respondWith(Result::fail('FORBIDDEN', 'notification.template_forbidden', 403));
        }

        return $this->renderForm('WBS\\Notifications\\Views\\template_edit', [
            'template' => $row,
        ]);
    }

    public function revise(string $templateId = '')
    {
        $row = NotificationServices::notificationTemplates(false)->find($this->orgId(), $templateId);
        if ($row === null) {
            return $this->deny(Result::notFound('notification.template_not_found', 'TEMPLATE_NOT_FOUND'));
        }
        $scope = ($row['group_id'] ?? null) !== null && (string) $row['group_id'] !== ''
            ? (string) $row['group_id'] : null;
        if (! $this->canManageGroupScope('provider.configure', $scope)) {
            return $this->deny();
        }
        $result = NotificationServices::notificationTemplates(false)->revise($this->orgId(), $templateId, $this->input());

        return $this->prg($result, 'templateRevisedFlash');
    }

    public function retire(string $templateId = '')
    {
        $row = NotificationServices::notificationTemplates(false)->find($this->orgId(), $templateId);
        if ($row === null) {
            return $this->deny(Result::notFound('notification.template_not_found', 'TEMPLATE_NOT_FOUND'));
        }
        $scope = ($row['group_id'] ?? null) !== null && (string) $row['group_id'] !== ''
            ? (string) $row['group_id'] : null;
        if (! $this->canManageGroupScope('provider.configure', $scope)) {
            return $this->deny();
        }
        $result = NotificationServices::notificationTemplates(false)->retire($this->orgId(), $templateId);

        return $this->prg($result, 'templateRetiredFlash');
    }

    private function deny(?Result $fail = null)
    {
        $fail ??= Result::fail('FORBIDDEN', 'notification.template_forbidden', 403);
        if ($this->wantsJson()) {
            return $this->respondWith($fail);
        }

        return redirect()->to(self::DASHBOARD)->with('error', $this->errText((string) $fail->message));
    }

    private function prg(Result $result, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to(self::DASHBOARD)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to(self::DASHBOARD)->with('success', lang('Notifications.templates.' . $okKey));
    }

    /** @return list<array<string,mixed>> */
    private function groupsInScope(): array
    {
        $out = [];
        foreach (GroupServices::groups()->listForOrg($this->orgId(), 2000) as $g) {
            $id = (string) ($g['id'] ?? '');
            if ($id !== '' && $this->canManageGroupScope('provider.configure', $id)) {
                $out[] = $g;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function knownKeys(): array
    {
        return [
            'account_verify_reminder', 'event_reminder', 'event_cancelled', 'event_updated',
            'event_promoted', 'security_alert', 'access_request', 'access_request_outcome',
            'stream_relay_failure', 'partnership_commitment_due', 'outreach_follow_up_due',
            'gamification_review_reminder', 'gamification_review_escalation',
            'journey_proposal_reminder', 'journey_proposal_escalation',
            'announcement_published',
        ];
    }
}
