<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Manual (offline / in-kind) contributions: cash, cheque, bank transfer, and
 * in-kind goods/services/time. Two-step maker-checker:
 *
 *   submit()  -> manual_contribution_records (status=submitted)   [finance staff]
 *   approve() -> creates a verified `contributions` row + posts ledger + stages
 *                the reward/metrics event                          [DIFFERENT staff]
 *
 * Adaptation notes vs. GivingsLibrary:
 *  - In-kind "time" is valued at a configurable hourly rate; goods/services use
 *    a staff-appraised value. Money is BIGINT minor units.
 *  - Segregation of duties: approver must differ from submitter (same stance as
 *    RefundService).
 *  - On approval the contribution is created state='succeeded' and the SAME
 *    contribution.succeeded outbox event as online givings is staged, so points
 *    (RewardCoordinator) AND PGV/GGV/partnership refresh happen through one path.
 */
final class ManualContributionService
{
    /** Valuation rate for in-kind "time" contributions, per the design docs (minor units/hour). */
    private const IN_KIND_HOURLY_RATE_MINOR = 2500; // 25.00

    private const TYPES = ['cash', 'cheque', 'bank_transfer', 'in_kind'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly CauseService $causes,
        private readonly LedgerService $ledger,
        private readonly OutboxService $outbox,
    ) {
    }

    /**
     * Record a pending manual giving (maker step).
     *
     * @param array<string,mixed> $data cause_id, type, currency, user_id,
     *          stated_value_minor (goods/services or cash), in_kind_category
     *          (time|goods|services), in_kind_hours, valuation_method,
     *          received_date, custodian_id, evidence_ref, recognition
     */
    public function submit(string $organizationId, string $submittedBy, array $data): Result
    {
        $causeId = (string) ($data['cause_id'] ?? '');
        $type    = (string) ($data['type'] ?? '');
        if ($causeId === '' || $submittedBy === '') {
            return Result::fail('BAD_MANUAL', 'manual.cause_submitter_required', 422);
        }
        if (! in_array($type, self::TYPES, true)) {
            return Result::fail('BAD_TYPE', 'manual.bad_type', 422, ['allowed' => self::TYPES]);
        }

        $cause = $this->causes->find($causeId);
        if ($cause === null || (string) ($cause['organization_id'] ?? '') !== $organizationId) {
            return Result::notFound('manual.cause_not_found', 'CAUSE_NOT_FOUND');
        }

        $valuation = $this->valuate($type, $data);
        if (! $valuation->ok) {
            return $valuation;
        }
        $valueMinor      = (int) $valuation->data['value_minor'];
        $valuationMethod = (string) $valuation->data['method'];

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('manual_contribution_records')->insert([
            'id'                 => $id,
            'organization_id'    => $organizationId,
            'cause_id'           => $causeId,
            'contribution_id'    => null,
            'type'               => $type,
            'stated_value_minor' => $valueMinor,
            'currency'           => strtoupper((string) ($data['currency'] ?? $cause['currency'] ?? 'GHS')),
            'valuation_method'   => mb_substr($valuationMethod, 0, 120),
            'received_date'      => $data['received_date'] ?? substr($now, 0, 10),
            'custodian_id'       => $data['custodian_id'] ?? null,
            'evidence_ref'       => $data['evidence_ref'] ?? null,
            'submitted_by'       => $submittedBy,
            'approved_by'        => null,
            'status'             => 'submitted',
            'created_at'         => $now,
        ]);

        return Result::created([
            'record_id'   => $id,
            'type'        => $type,
            'value_minor' => $valueMinor,
            'status'      => 'submitted',
        ]);
    }

    /**
     * Approve a manual record (checker step) — must be a different actor than
     * the submitter. Creates the verified contribution, posts the ledger, and
     * stages the shared contribution.succeeded event.
     *
     * @param array<string,mixed> $opts user_id (attributed giver), recognition
     */
    public function approve(string $organizationId, string $recordId, string $approvedBy, array $opts = []): Result
    {
        $rec = $this->db->table('manual_contribution_records')
            ->where('organization_id', $organizationId)->where('id', $recordId)
            ->get()->getRowArray();
        if ($rec === null) {
            return Result::notFound('manual.record_not_found', 'RECORD_NOT_FOUND');
        }
        if ($rec['status'] !== 'submitted') {
            return Result::fail('BAD_STATE', 'manual.not_submitted', 409, ['status' => $rec['status']]);
        }
        // Segregation of duties: approver != submitter.
        if ((string) $rec['submitted_by'] === $approvedBy) {
            return Result::denied('manual.self_approval', 'SELF_APPROVAL');
        }

        $amount   = (int) $rec['stated_value_minor'];
        $currency = (string) $rec['currency'];
        $userId   = (string) ($opts['user_id'] ?? '');
        $conId    = Uuid::v7();
        $now      = $this->clock->nowUtcMicro();

        $this->db->transStart();
        try {
            $this->db->table('contributions')->insert([
                'id'              => $conId,
                'organization_id' => $organizationId,
                'cause_id'        => $rec['cause_id'],
                'intent_id'       => null,
                'user_id'         => $userId !== '' ? $userId : null,
                'amount_minor'    => $amount,
                'currency'        => $currency,
                'fee_minor'       => 0,
                'net_minor'       => $amount,
                'source'          => 'manual',
                'recognition'     => (string) ($opts['recognition'] ?? 'public'),
                'state'           => 'succeeded',
                'verified_at'     => $now,
                'created_at'      => $now,
            ]);

            $this->db->table('manual_contribution_records')->where('id', $recordId)->update([
                'contribution_id' => $conId,
                'approved_by'     => $approvedBy,
                'status'          => 'posted',
                'updated_at'      => $now,
            ]);

            // Double-entry: in-kind/manual funds recognized against cause_funds.
            $account = $rec['type'] === 'in_kind' ? 'in_kind_assets' : 'cash_clearing';
            $this->ledger->post($organizationId, 'succeeded', $currency, [
                ['account' => $account, 'direction' => 'debit', 'amount_minor' => $amount],
                ['account' => 'cause_funds', 'direction' => 'credit', 'amount_minor' => $amount],
            ], 'manual:' . $conId, [
                'cause_id'        => $rec['cause_id'],
                'contribution_id' => $conId,
                'memo'            => 'Manual contribution posted (' . $rec['type'] . ')',
            ]);

            // Same event online givings use -> points + PGV/GGV + partnership.
            if ($userId !== '') {
                $this->outbox->stage('contribution', $conId, 'contribution.succeeded', [
                    'contribution_id' => $conId,
                    'cause_id'        => $rec['cause_id'],
                    'user_id'         => $userId,
                    'amount_minor'    => $amount,
                    'source_ref'      => 'contribution:' . $conId,
                ], $organizationId);
            }
        } catch (Throwable $e) {
            $this->db->transRollback();

            return Result::fail('APPROVE_FAILED', 'manual.approve_failed', 500, ['detail' => $e->getMessage()]);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('APPROVE_FAILED', 'manual.approve_failed', 500);
        }

        return Result::ok(['record_id' => $recordId, 'contribution_id' => $conId, 'status' => 'posted']);
    }

    /** Reject a submitted record. */
    public function reject(string $organizationId, string $recordId, string $rejectedBy, string $reason = ''): Result
    {
        $rec = $this->db->table('manual_contribution_records')
            ->where('organization_id', $organizationId)->where('id', $recordId)
            ->get()->getRowArray();
        if ($rec === null) {
            return Result::notFound('manual.record_not_found', 'RECORD_NOT_FOUND');
        }
        if ($rec['status'] !== 'submitted') {
            return Result::fail('BAD_STATE', 'manual.not_submitted', 409, ['status' => $rec['status']]);
        }
        $this->db->table('manual_contribution_records')->where('id', $recordId)->update([
            'approved_by' => $rejectedBy,
            'status'      => 'rejected',
            'updated_at'  => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['record_id' => $recordId, 'status' => 'rejected', 'reason' => $reason]);
    }

    /** @return list<array<string,mixed>> Records pending approval. */
    public function pending(string $organizationId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table('manual_contribution_records')
            ->where('organization_id', $organizationId)->where('status', 'submitted')
            ->orderBy('created_at', 'ASC')->limit($limit, $offset)
            ->get()->getResultArray();
    }

    /**
     * Resolve a monetary value (minor units) for a manual giving.
     *  - in_kind + time  -> hours * hourly rate
     *  - in_kind + goods/services -> staff-appraised stated_value_minor
     *  - cash/cheque/bank_transfer -> stated_value_minor
     */
    private function valuate(string $type, array $data): Result
    {
        if ($type === 'in_kind') {
            $category = (string) ($data['in_kind_category'] ?? '');
            if ($category === 'time') {
                $hours = (float) ($data['in_kind_hours'] ?? 0);
                if ($hours <= 0) {
                    return Result::fail('BAD_HOURS', 'manual.hours_positive', 422);
                }

                return Result::ok([
                    'value_minor' => (int) round($hours * self::IN_KIND_HOURLY_RATE_MINOR),
                    'method'      => sprintf('time@%d/hr x %.2fh', self::IN_KIND_HOURLY_RATE_MINOR, $hours),
                ]);
            }
            // goods/services: appraised value required.
            $value = (int) ($data['stated_value_minor'] ?? 0);
            if ($value <= 0) {
                return Result::fail('BAD_APPRAISAL', 'manual.appraisal_required', 422);
            }

            return Result::ok([
                'value_minor' => $value,
                'method'      => (string) ($data['valuation_method'] ?? 'appraised'),
            ]);
        }

        // Monetary manual channels.
        $value = (int) ($data['stated_value_minor'] ?? 0);
        if ($value <= 0) {
            return Result::fail('BAD_AMOUNT', 'manual.amount_positive', 422);
        }

        return Result::ok(['value_minor' => $value, 'method' => (string) ($data['valuation_method'] ?? $type)]);
    }
}
