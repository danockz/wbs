<?php

declare(strict_types=1);

namespace WBS\Reporting\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Reporting\Config\Services as ReportingServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Reporting dashboards + export endpoints (SRS FR-RPT-*).
 *
 * All routes sit behind auth + an authorize:report.view / report.export filter;
 * exports are checked again at download inside the service.
 */
final class DashboardController extends BaseController
{
    /**
     * GET reports/export — export history (the Reports → Exports landing page).
     * Browsers get the bespoke exports view; API clients get JSON. Read-only;
     * artifact keys are never returned — downloads go through download().
     */
    public function exports()
    {
        $status  = $this->field('status');
        $exports = ReportingServices::exports()->listForOrg(
            $this->orgId(),
            $status !== null ? (string) $status : null,
        );

        return $this->respondWith(
            Result::ok(['exports' => $exports]),
            'WBS\Reporting\Views\exports',
            null,
            [
                'exports' => $exports,
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function funnel()
    {
        $orgId = $this->orgId();

        $result = ReportingServices::dashboards()->wbsFunnel($orgId, [
            'group_id' => $this->field('group_id'),
        ]);

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Reporting\Views\funnel',
            viewData: ['result' => $result->data, 'title' => 'WBS funnel'],
        );
    }

    /**
     * The authenticated member's personal dashboard ("my home").
     *
     * Self-scoped: it always uses the authenticated user id and NEVER a
     * caller-supplied one, so this needs no admin/report permission — only the
     * `auth` filter. A member can see only their own certificates, upcoming
     * events, course progress, gamification standing and group memberships.
     */
    public function me()
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();

        $result = ReportingServices::memberDashboard()->forUser($orgId, $userId);

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Reporting\Views\member_dashboard',
            viewData: ['result' => $result->data, 'title' => 'My dashboard'],
        );
    }

    public function requestExport()
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        $by    = $this->currentUserId('requested_by');

        $result = ReportingServices::exports()->request(
            $orgId,
            $by,
            (string) ($in['report_key'] ?? 'wbs_funnel'),
            (array) ($in['params'] ?? []),
            (string) ($in['format'] ?? 'csv'),
        );

        // API clients keep the raw Result (JSON); a browser is PRG-redirected back
        // to the export history with a localized flash so a reload never re-queues.
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/reports/export';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Reporting.exports.requestedFlash'));
    }

    public function download(string $exportId = '')
    {
        $by = $this->currentUserId('requester_id');

        return $this->respondWith(ReportingServices::exports()->download($exportId, $by));
    }
}
