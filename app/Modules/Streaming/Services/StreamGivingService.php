<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Contributions\Services\CauseService;
use WBS\Contributions\Services\ContributionService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * In-stream giving (SRS FR-STR-009).
 *
 * A stream displays an authorized cause-progress bar and a contribution widget
 * that invokes the VBCS payment flow WITHOUT leaving the stream page. This
 * service:
 *
 *  - configureGiving(): an authorized organizer turns giving on for a stream and
 *    binds it to a specific cause + widget settings (the authorization gate).
 *  - widget(): the public, in-page widget descriptor a viewer's stream page
 *    renders — progress bar + suggested amounts. It NEVER exposes anything
 *    beyond public cause progress.
 *  - give(): a viewer contributes in-page. It validates the giving config, then
 *    creates a Contributions INTENT (money completes later via the signed
 *    provider webhook — we never fake a completion) and records the giver's
 *    acknowledgement preferences.
 *  - acknowledgements(): the opt-in shout-out feed. It shows ONLY gifts that
 *    actually succeeded, honours the giver's name/Anonymous choice, and reveals
 *    the amount only when the giver opted in AND stream policy permits it.
 *
 * Provider checkout security is unchanged: no PAN/CVV touches these servers; the
 * approved adapter creates the real checkout and a signed webhook confirms it.
 */
final class StreamGivingService
{
    private const RECOGNITIONS = ['public', 'group', 'anonymous', 'none'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ContributionService $contributions,
        private readonly CauseService $causes,
    ) {
    }

    /**
     * Authorize + configure in-stream giving for a stream (organizer action).
     *
     * @param array<string,mixed> $data cause_id, enabled, progress_bar_enabled,
     *   widget_enabled, suggested_amounts[], min_amount_minor, max_amount_minor,
     *   currency, allow_anonymous, ack_enabled, ack_show_amount_allowed
     */
    public function configureGiving(string $organizationId, string $streamId, string $authorizedBy, array $data): Result
    {
        $stream = $this->db->table('streams')
            ->where('id', $streamId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $enabled = ! empty($data['enabled']);
        $causeId = isset($data['cause_id']) ? (string) $data['cause_id'] : '';

        // Enabling giving REQUIRES a valid, active cause in this org.
        if ($enabled) {
            if ($causeId === '') {
                return Result::fail('CAUSE_REQUIRED', 'stream.giving_cause_required', 422);
            }
            $cause = $this->causes->find($causeId);
            if ($cause === null || (string) $cause['organization_id'] !== $organizationId) {
                return Result::notFound('cause.not_found', 'CAUSE_NOT_FOUND');
            }
            if (($cause['status'] ?? '') !== 'active') {
                return Result::fail('CAUSE_INACTIVE', 'stream.giving_cause_inactive', 409, ['status' => $cause['status'] ?? null]);
            }
        }

        $now  = $this->clock->nowUtcString();
        $rows = [
            'enabled'                 => $enabled ? 1 : 0,
            'cause_id'                => $causeId !== '' ? $causeId : null,
            'progress_bar_enabled'   => array_key_exists('progress_bar_enabled', $data) ? (! empty($data['progress_bar_enabled']) ? 1 : 0) : 1,
            'widget_enabled'         => array_key_exists('widget_enabled', $data) ? (! empty($data['widget_enabled']) ? 1 : 0) : 1,
            'suggested_amounts'      => isset($data['suggested_amounts']) ? json_encode($this->cleanAmounts($data['suggested_amounts'])) : null,
            'min_amount_minor'       => isset($data['min_amount_minor']) ? max(0, (int) $data['min_amount_minor']) : null,
            'max_amount_minor'       => isset($data['max_amount_minor']) ? max(0, (int) $data['max_amount_minor']) : null,
            'currency'               => isset($data['currency']) && $data['currency'] !== '' ? strtoupper((string) $data['currency']) : null,
            'allow_anonymous'        => array_key_exists('allow_anonymous', $data) ? (! empty($data['allow_anonymous']) ? 1 : 0) : 1,
            'ack_enabled'            => array_key_exists('ack_enabled', $data) ? (! empty($data['ack_enabled']) ? 1 : 0) : 1,
            'ack_show_amount_allowed' => ! empty($data['ack_show_amount_allowed']) ? 1 : 0,
            'authorized_by'          => $authorizedBy,
            'authorized_at'          => $now,
            'updated_at'             => $now,
        ];

        $existing = $this->db->table('stream_giving_configs')->where('stream_id', $streamId)->get()->getRowArray();
        if ($existing !== null) {
            $this->db->table('stream_giving_configs')->where('id', $existing['id'])->update($rows);
            $id = $existing['id'];
        } else {
            $id = Uuid::v7();
            $this->db->table('stream_giving_configs')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'stream_id'       => $streamId,
                'created_at'      => $now,
            ] + $rows);
        }

        return Result::ok(['config_id' => $id, 'stream_id' => $streamId, 'enabled' => $enabled]);
    }

    /** @return array<string,mixed>|null the raw config row for a stream. */
    public function config(string $organizationId, string $streamId): ?array
    {
        return $this->db->table('stream_giving_configs')
            ->where('organization_id', $organizationId)->where('stream_id', $streamId)
            ->get()->getRowArray() ?: null;
    }

    /**
     * Public, in-page widget descriptor rendered on the stream page. Returns a
     * disabled marker (not an error) when giving is off, so the page can simply
     * hide the widget. Progress figures are public cause progress only.
     */
    public function widget(string $organizationId, string $streamId): Result
    {
        $cfg = $this->config($organizationId, $streamId);
        if ($cfg === null || (int) $cfg['enabled'] !== 1 || empty($cfg['cause_id'])) {
            return Result::ok(['stream_id' => $streamId, 'enabled' => false]);
        }

        $progress = null;
        if ((int) $cfg['progress_bar_enabled'] === 1) {
            $p = $this->causes->progress($organizationId, (string) $cfg['cause_id'], false);
            $progress = $p->ok ? $p->data : null;
        }

        return Result::ok([
            'stream_id'         => $streamId,
            'enabled'           => true,
            'widget_enabled'    => (bool) $cfg['widget_enabled'],
            'cause_id'          => $cfg['cause_id'],
            'currency'          => $cfg['currency'],
            'suggested_amounts' => $cfg['suggested_amounts'] !== null ? json_decode((string) $cfg['suggested_amounts'], true) : [],
            'min_amount_minor'  => $cfg['min_amount_minor'] !== null ? (int) $cfg['min_amount_minor'] : null,
            'max_amount_minor'  => $cfg['max_amount_minor'] !== null ? (int) $cfg['max_amount_minor'] : null,
            'allow_anonymous'   => (bool) $cfg['allow_anonymous'],
            'ack_enabled'       => (bool) $cfg['ack_enabled'],
            'ack_show_amount_allowed' => (bool) $cfg['ack_show_amount_allowed'],
            'progress'          => $progress,
        ]);
    }

    /**
     * A viewer gives in-page. Validates against the stream's giving config, then
     * creates a Contributions intent (completion arrives via the provider
     * webhook) and records acknowledgement preferences.
     *
     * @param array<string,mixed> $data amount_minor, currency, provider,
     *   user_id, recognition, ack_opt_in, ack_show_amount, display_choice,
     *   idempotency_key
     */
    public function give(string $organizationId, string $streamId, array $data): Result
    {
        $cfg = $this->config($organizationId, $streamId);
        if ($cfg === null || (int) $cfg['enabled'] !== 1 || empty($cfg['cause_id'])) {
            return Result::fail('GIVING_DISABLED', 'stream.giving_disabled', 409);
        }
        if ((int) $cfg['widget_enabled'] !== 1) {
            return Result::fail('WIDGET_DISABLED', 'stream.giving_widget_disabled', 409);
        }

        // Live-only gate (gap ST1): giving is money — accept it ONLY while the
        // broadcast is actually live, so a lingering widget on a draft or already-
        // ended stream can't keep capturing contributions outside the window the
        // giver believes they are supporting. The stream must exist, be in-org,
        // and be `live`.
        $stream = $this->db->table('streams')
            ->where('id', $streamId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }
        if ((string) $stream['status'] !== 'live') {
            return Result::fail('STREAM_NOT_LIVE', 'stream.giving_not_live', 409, ['status' => $stream['status']]);
        }

        $causeId = (string) $cfg['cause_id'];
        $amount  = (int) ($data['amount_minor'] ?? 0);
        if ($amount <= 0) {
            return Result::fail('BAD_AMOUNT', 'stream.giving_bad_amount', 422);
        }
        if ($cfg['min_amount_minor'] !== null && $amount < (int) $cfg['min_amount_minor']) {
            return Result::fail('AMOUNT_TOO_LOW', 'stream.giving_amount_too_low', 422, ['min' => (int) $cfg['min_amount_minor']]);
        }
        if ($cfg['max_amount_minor'] !== null && $amount > (int) $cfg['max_amount_minor']) {
            return Result::fail('AMOUNT_TOO_HIGH', 'stream.giving_amount_too_high', 422, ['max' => (int) $cfg['max_amount_minor']]);
        }

        $currency = strtoupper((string) ($data['currency'] ?? $cfg['currency'] ?? ''));
        if (strlen($currency) !== 3) {
            return Result::fail('BAD_CURRENCY', 'stream.giving_bad_currency', 422);
        }

        // Recognition + acknowledgement preferences.
        $recognition = in_array($data['recognition'] ?? '', self::RECOGNITIONS, true)
            ? (string) $data['recognition']
            : 'public';
        $userId        = isset($data['user_id']) && $data['user_id'] !== '' ? (string) $data['user_id'] : null;
        $displayChoice = ($data['display_choice'] ?? '') === 'name' ? 'name' : 'anonymous';
        // Can't reveal a name for an anonymous/none recognition or an anonymous user.
        if ($recognition === 'anonymous' || $recognition === 'none' || $userId === null) {
            $displayChoice = 'anonymous';
        }
        if ($displayChoice === 'anonymous' && (int) $cfg['allow_anonymous'] !== 1 && $userId !== null) {
            // Anonymous display disabled by the organizer: fall back to name.
            $displayChoice = 'name';
        }
        $ackOptIn      = (int) $cfg['ack_enabled'] === 1 && ! empty($data['ack_opt_in']);
        // Amount reveal requires BOTH the giver's choice AND the policy gate.
        $ackShowAmount = $ackOptIn
            && ! empty($data['ack_show_amount'])
            && (int) $cfg['ack_show_amount_allowed'] === 1;

        // Deterministic idempotency so a double-tap doesn't create two intents.
        $idem = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : ('stream:' . $streamId . ':' . Uuid::v7());

        $intent = $this->contributions->createIntent($organizationId, $causeId, [
            'amount_minor'    => $amount,
            'currency'        => $currency,
            'provider'        => $data['provider'] ?? null,
            'user_id'         => $userId,
            'recognition'     => $recognition,
            'idempotency_key' => $idem,
        ]);
        if ($intent->failed()) {
            return $intent;
        }

        $intentId = (string) ($intent->data['intent_id'] ?? '');

        // Persist the stream<->contribution link + acknowledgement preferences.
        // Idempotent on intent_id (deduplicated intents reuse the same row).
        if ($intentId !== '' && $this->db->table('stream_giving_intents')->where('intent_id', $intentId)->countAllResults() === 0) {
            $this->db->table('stream_giving_intents')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'stream_id'       => $streamId,
                'cause_id'        => $causeId,
                'intent_id'       => $intentId,
                'user_id'         => $userId,
                'amount_minor'    => $amount,
                'currency'        => $currency,
                'recognition'     => $recognition,
                'ack_opt_in'      => $ackOptIn ? 1 : 0,
                'ack_show_amount' => $ackShowAmount ? 1 : 0,
                'display_choice'  => $displayChoice,
                'created_at'      => $this->clock->nowUtcMicro(),
            ]);
        }

        return Result::created([
            'stream_id' => $streamId,
            'cause_id'  => $causeId,
            'intent'    => $intent->data,
            'note'      => 'giving intent created; complete checkout in-page; completion arrives via the provider webhook',
        ], ['deferred_completion' => true]);
    }

    /**
     * Opt-in acknowledgement feed for a stream (the on-screen shout-outs).
     *
     * Only gifts that ACTUALLY succeeded appear (join to contributions on
     * intent_id, state='succeeded'). Name is shown only for a name display
     * choice; otherwise "Anonymous". Amount is included ONLY when the giver
     * opted to reveal it AND stream policy allows it.
     *
     * @return list<array<string,mixed>>
     */
    public function acknowledgements(string $organizationId, string $streamId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $rows = $this->db->table('stream_giving_intents sgi')
            ->select('sgi.display_choice, sgi.ack_show_amount, sgi.amount_minor, sgi.currency, '
                . 'c.verified_at, u.display_name', false)
            ->join('contributions c', 'c.intent_id = sgi.intent_id', 'inner')
            ->join('users u', 'u.id = sgi.user_id', 'left')
            ->where('sgi.organization_id', $organizationId)
            ->where('sgi.stream_id', $streamId)
            ->where('sgi.ack_opt_in', 1)
            ->where('c.state', 'succeeded')
            ->orderBy('c.verified_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $showName = $r['display_choice'] === 'name' && ! empty($r['display_name']);
            $ack = [
                'display_name' => $showName ? $r['display_name'] : 'Anonymous',
                'given_at'     => $r['verified_at'],
            ];
            // Amount is opt-in AND policy-gated (both already enforced at give()).
            if ((int) $r['ack_show_amount'] === 1) {
                $ack['amount_minor'] = (int) $r['amount_minor'];
                $ack['currency']     = $r['currency'];
            }
            $out[] = $ack;
        }

        return $out;
    }

    /**
     * Normalize a suggested-amount list to positive integers (minor units).
     *
     * @param mixed $amounts
     *
     * @return list<int>
     */
    private function cleanAmounts(mixed $amounts): array
    {
        if (! is_array($amounts)) {
            return [];
        }
        $out = [];
        foreach ($amounts as $a) {
            $v = (int) $a;
            if ($v > 0) {
                $out[] = $v;
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }
}
