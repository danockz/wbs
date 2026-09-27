<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Check-in kiosks and offline reconciliation (SRS FR-EVT-017).
 *
 * GET events/{id}/kiosks is a browser KIOSK CONSOLE: the event's registered
 * kiosks with a register form and per-kiosk manage actions (refresh manifest /
 * reconcile queued offline scans / revoke). The device-facing offline-scan
 * ENQUEUE endpoint stays JSON-only — it is called by the kiosk app, not a browser.
 * All console POSTs are webcsrf-guarded; API clients keep JSON.
 */
final class KioskController extends BaseController
{
    /**
     * GET events/{id}/kiosks — the KIOSK CONSOLE: list the event's kiosks (label,
     * status, manifest version, queued offline scans) plus a no-JS PRG register
     * form and a per-kiosk manage row. API clients get the same data as JSON.
     */
    public function console(string $eventId = '')
    {
        $kiosks = EventServices::kiosks()->listForEvent($eventId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['event_id' => $eventId, 'kiosks' => $kiosks]));
        }

        return $this->renderForm('WBS\Events\Views\kiosk_console', [
            'eventId' => $eventId,
            'kiosks'  => $kiosks,
        ]);
    }

    public function register(string $eventId = '')
    {
        $in = $this->input();

        $result = EventServices::kiosks()->registerKiosk(
            $this->orgId(),
            $eventId,
            (string) ($in['device_label'] ?? ''),
            isset($in['device_fingerprint']) ? (string) $in['device_fingerprint'] : null,
            $this->actorId(),
        );

        return $this->respondKiosk($result, $eventId, 'registeredFlash');
    }

    public function revoke(string $kioskId = '')
    {
        $result = EventServices::kiosks()->revokeKiosk($kioskId);

        return $this->respondKiosk($result, (string) ($result->data['event_id'] ?? ''), 'revokedFlash');
    }

    /** Issue a fresh encrypted, short-lived, event-scoped manifest. */
    public function manifest(string $kioskId = '')
    {
        $result = EventServices::kiosks()->issueManifest($kioskId);

        // API clients need the ciphertext envelope; a browser only needs the flash.
        return $this->respondKiosk($result, (string) ($result->data['event_id'] ?? ''), 'manifestFlash');
    }

    /** Enqueue a batch of offline scans captured while disconnected (device API). */
    public function enqueueScans(string $kioskId = '')
    {
        $in    = $this->input();
        $scans = $in['scans'] ?? [];

        return $this->respondWith(EventServices::kiosks()->enqueueOfflineScans(
            $this->orgId(),
            $kioskId,
            is_array($scans) ? $scans : [],
        ));
    }

    /** Reconcile queued offline scans server-side (one-active-attendance rules). */
    public function reconcile(string $kioskId = '')
    {
        $result = EventServices::kiosks()->reconcile(
            $kioskId,
            $this->currentUserId('actor_id'),
        );

        return $this->respondKiosk($result, (string) ($result->data['event_id'] ?? ''), 'reconciledFlash');
    }

    /**
     * PRG for a browser kiosk write: API clients keep the raw Result (JSON);
     * browsers redirect back to the event kiosk console with a localized success
     * flash, or the failing Result's message as an error flash.
     */
    private function respondKiosk(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/kiosks' : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.kiosk.' . $okKey));
    }
}
