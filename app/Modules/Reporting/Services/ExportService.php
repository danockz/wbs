<?php

declare(strict_types=1);

namespace WBS\Reporting\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Asynchronous, permission-checked, formula-injection-safe exports (SRS FR-RPT-004).
 *
 * request() only enqueues an export job (permission checked at the route). A
 * worker later runs it and calls markReady() with the private artifact ref.
 * download() re-checks entitlement (requester + not expired) before releasing
 * the artifact reference. CSV cell values are neutralized against spreadsheet
 * formula injection.
 */
final class ExportService
{
    /** Leading characters that make a spreadsheet treat a cell as a formula. */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Export history for an organization (read-only), newest first, optionally
     * filtered by status. Never returns artifact_ref (the private storage key) —
     * downloads go through download() with its own re-check — so the history page
     * is safe to render.
     *
     * @return list<array<string,mixed>>
     */
    public function listForOrg(string $organizationId, ?string $status = null, int $limit = 200): array
    {
        $q = $this->db->table('report_exports')
            ->select('id, report_key, format, status, row_count, requested_by, created_at, completed_at, expires_at')
            ->where('organization_id', $organizationId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at', 'DESC')
            ->get(max(1, min(500, $limit)))
            ->getResultArray();
    }

    /** @param array<string,mixed> $params */
    public function request(string $organizationId, string $requestedBy, string $reportKey, array $params = [], string $format = 'csv'): Result
    {
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return Result::fail('BAD_FORMAT', 'export.bad_format', 422);
        }
        $id = Uuid::v7();
        $this->db->table('report_exports')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'requested_by'    => $requestedBy,
            'report_key'      => $reportKey,
            'params'          => json_encode($params),
            'format'          => $format,
            'status'          => 'queued',
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);

        // A worker consumes report_exports rows with status=queued.
        return Result::created(['export_id' => $id, 'status' => 'queued']);
    }

    public function markRunning(string $exportId): Result
    {
        $this->db->table('report_exports')->where('id', $exportId)->where('status', 'queued')
            ->update(['status' => 'running']);

        return Result::ok(['export_id' => $exportId, 'status' => 'running']);
    }

    public function markReady(string $exportId, string $artifactRef, int $rowCount, int $ttlSeconds = 86400, ?string $watermark = null): Result
    {
        $now     = $this->clock->nowUtcMicro();
        $expires = $this->clock->now()->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $this->db->table('report_exports')->where('id', $exportId)->update([
            'status'       => 'ready',
            'artifact_ref' => $artifactRef,
            'row_count'    => $rowCount,
            'watermark'    => $watermark,
            'completed_at' => $now,
            'expires_at'   => $expires,
        ]);

        return Result::ok(['export_id' => $exportId, 'status' => 'ready', 'expires_at' => $expires]);
    }

    public function markFailed(string $exportId, string $error): Result
    {
        $this->db->table('report_exports')->where('id', $exportId)->update([
            'status' => 'failed',
            'error'  => mb_substr($error, 0, 500),
        ]);

        return Result::ok(['export_id' => $exportId, 'status' => 'failed']);
    }

    /**
     * Re-check entitlement at download time (FR-RPT-004): only the requester,
     * only while ready and unexpired. Returns the private artifact reference.
     */
    public function download(string $exportId, string $requesterId): Result
    {
        $row = $this->db->table('report_exports')->where('id', $exportId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('export.not_found', 'EXPORT_NOT_FOUND');
        }
        if ($row['requested_by'] !== $requesterId) {
            return Result::denied('export.forbidden', 'FORBIDDEN');
        }
        if ($row['status'] !== 'ready') {
            return Result::fail('NOT_READY', 'export.not_ready', 409, [], ['status' => $row['status']]);
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < $this->clock->nowUtcMicro()) {
            $this->db->table('report_exports')->where('id', $exportId)->update(['status' => 'expired']);

            return Result::fail('EXPIRED', 'export.expired', 410);
        }

        $this->db->table('report_exports')->where('id', $exportId)
            ->update(['downloaded_at' => $this->clock->nowUtcMicro()]);

        return Result::ok([
            'export_id'    => $exportId,
            'artifact_ref' => $row['artifact_ref'],
            'format'       => $row['format'],
            'watermark'    => $row['watermark'],
        ]);
    }

    /**
     * Render rows to CSV with formula-injection mitigation. Header + rows are
     * arrays of scalars.
     *
     * @param list<string>        $header
     * @param list<array<scalar>> $rows
     */
    public function renderCsv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map([$this, 'neutralize'], $header));
        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'neutralize'], array_values($row)));
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);

        return $csv;
    }

    /** Prefix a leading formula trigger with an apostrophe so it stays inert text. */
    public function neutralize(mixed $value): string
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], self::FORMULA_TRIGGERS, true)) {
            return "'" . $s;
        }

        return $s;
    }
}
