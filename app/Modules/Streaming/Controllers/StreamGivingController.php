<?php

declare(strict_types=1);

namespace WBS\Streaming\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;
use WBS\Streaming\Config\Services as StreamingServices;
use WBS\Contributions\Config\Services as ContributionServices;

/**
 * In-stream giving endpoints (SRS FR-STR-009).
 *
 * Configuration is an organizer action gated by `authorize:stream.moderate`.
 * The widget descriptor, the acknowledgement feed, and the in-page `give`
 * action are viewer-facing: a public stream needs no session (giving works
 * without leaving the page), so those are rate-limited rather than auth-gated.
 * The giver identity, when present, is taken from the authenticated session,
 * never trusted from the body.
 *
 * The organizer config page (showConfig) is now a bespoke WRITE surface: it shows
 * the current configuration AND an edit form (enable + cause + widget/progress
 * toggles + suggested amounts + min/max + currency + anonymity/ack). The form
 * POSTs to the webcsrf-guarded configure route; the controller PRG-redirects back
 * to the config page with a localized flash (JSON preserved for API clients). The
 * authorizing organizer identity comes from the authenticated session.
 */
final class StreamGivingController extends BaseController
{
    /** Organizer: authorize + configure giving for a stream. */
    public function configure(string $streamId = '')
    {
        $in = $this->input();
        // A browser form posts suggested amounts as a comma/space separated string;
        // the service's cleanAmounts() expects an array. Normalize a string here so
        // both the form and JSON-array API callers work.
        if (isset($in['suggested_amounts']) && is_string($in['suggested_amounts'])) {
            $parts = preg_split('/[,\s]+/', trim($in['suggested_amounts'])) ?: [];
            $in['suggested_amounts'] = array_values(array_filter($parts, static fn ($p) => $p !== ''));
        }

        $result = StreamingServices::streamGiving()->configureGiving(
            $this->orgId(),
            $streamId,
            $this->currentUserId('actor_id'),
            $in,
        );

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $streamId !== '' ? '/streams/' . rawurlencode($streamId) . '/giving/config' : '/streams';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Streaming.givingConfig.savedFlash'));
    }

    /** Organizer: read + edit the giving config. */
    public function showConfig(string $streamId = '')
    {
        $cfg     = StreamingServices::streamGiving()->config($this->orgId(), $streamId);
        $payload = $cfg ?? ['stream_id' => $streamId, 'enabled' => false];
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($payload), 'Stream giving config', $streamId);
        }

        // cause_id is an entity reference → give the view the active causes so it
        // can render a name→id picker instead of a raw ID text box.
        $causeList = ContributionServices::causes()->list($this->orgId(), ['status' => 'active', 'limit' => 200]);
        $causes    = ($causeList->ok && is_array($causeList->data)) ? ($causeList->data['causes'] ?? []) : [];

        return $this->respondWith(
            Result::ok($payload),
            'WBS\Streaming\Views\giving_config',
            null,
            [
                'config'   => $payload,
                'streamId' => $streamId,
                'causes'   => $causes,
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Public: the in-page widget descriptor (progress bar + suggested amounts). */
    public function widget(string $streamId = '')
    {
        return $this->respondWith(StreamingServices::streamGiving()->widget($this->orgId(), $streamId));
    }

    /** Public: give in-page. Creates a VBCS intent; completion via webhook. */
    public function give(string $streamId = '')
    {
        $in = $this->input();

        return $this->respondWith(StreamingServices::streamGiving()->give($this->orgId(), $streamId, [
            'amount_minor'    => $in['amount_minor'] ?? 0,
            'currency'        => $in['currency'] ?? null,
            'cause_id'        => $in['cause_id'] ?? null,
            'provider'        => $in['provider'] ?? null,
            'user_id'         => $this->actorId('user_id'),
            'recognition'     => $in['recognition'] ?? 'public',
            'ack_opt_in'      => $in['ack_opt_in'] ?? false,
            'ack_show_amount' => $in['ack_show_amount'] ?? false,
            'display_choice'  => $in['display_choice'] ?? 'anonymous',
            'idempotency_key' => $in['idempotency_key'] ?? ($this->request->getHeaderLine('Idempotency-Key') ?: null),
        ]));
    }

    /** Public: opt-in acknowledgement feed (name-or-Anonymous, amount gated). */
    public function acknowledgements(string $streamId = '')
    {
        $rows = StreamingServices::streamGiving()->acknowledgements(
            $this->orgId(),
            $streamId,
            (int) $this->field('limit', 20),
        );

        return $this->respondPage(
            Result::ok(['stream_id' => $streamId, 'acknowledgements' => $rows, 'count' => count($rows)]),
            'stream_acks',
            static fn (array $d): array => ['rows' => $d['acknowledgements'] ?? [], 'count' => $d['count'] ?? null],
        );
    }
}
