<?php

declare(strict_types=1);

namespace WBS\Events\Services;

/**
 * iCalendar (RFC 5545) feed builder for the Events module.
 *
 * PURE + DB-FREE: it turns already-fetched event rows into a VCALENDAR string,
 * so it is trivially unit-testable and cannot leak a query onto the hot path.
 * The controller does the ONE bounded read (EventService::feedInRange /
 * RegistrationService::myEvents) and hands the rows here.
 *
 * The feed is designed for calendar SUBSCRIPTION (webcal / "add by URL") in
 * Google Calendar, Apple Calendar and Outlook:
 *   - PUBLISH method, X-WR-CALNAME/X-WR-TIMEZONE so the calendar names itself;
 *   - one VEVENT per event, DTSTART/DTEND stamped in UTC ("Z") — event times are
 *     stored UTC, so no VTIMEZONE block is needed and every client agrees;
 *   - stable UID (event id @ host) so re-subscribing UPDATES rather than
 *     duplicates; SEQUENCE bumped from updated_at so edits propagate;
 *   - cancelled events emit STATUS:CANCELLED (feeds that include them) so a
 *     client removes them on refresh;
 *   - TEXT values escaped and lines folded at 75 octets per the spec.
 *
 * No secrets are emitted: a virtual event's access_url is included as URL only
 * when present (it is the join link the attendee already has via the event page).
 */
final class IcsFeedService
{
    /** Product identifier advertised in the calendar header. */
    private const PRODID = '-//WBS Platform//Events//EN';

    /**
     * Build a complete VCALENDAR document from event rows.
     *
     * @param list<array<string,mixed>> $events    rows (id,title,description,status,mode,access_url,starts_at,ends_at,updated_at,created_at)
     * @param string                    $calName   human calendar name (already localized by the caller)
     * @param string                    $host      host for UID scoping (e.g. 'public.test')
     * @param string                    $nowStamp  UTC "YYYY-MM-DD HH:MM:SS" DTSTAMP for this generation
     * @param string                    $baseUrl   absolute base (e.g. 'https://public.test/') for per-event URLs
     */
    public function build(array $events, string $calName, string $host, string $nowStamp, string $baseUrl = ''): string
    {
        $host    = $host !== '' ? $host : 'events.local';
        $stamp   = $this->stamp($nowStamp);
        $baseUrl = rtrim($baseUrl, '/');

        $lines   = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:' . self::PRODID;
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . $this->escapeText($calName);
        $lines[] = 'X-WR-TIMEZONE:UTC';

        foreach ($events as $e) {
            foreach ($this->vevent($e, $host, $stamp, $baseUrl) as $l) {
                $lines[] = $l;
            }
        }

        $lines[] = 'END:VCALENDAR';

        // Fold every line at 75 octets and join with CRLF (spec-mandated).
        $out = '';
        foreach ($lines as $l) {
            $out .= $this->fold($l) . "\r\n";
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $e
     *
     * @return list<string>
     */
    private function vevent(array $e, string $host, string $stamp, string $baseUrl): array
    {
        $id     = (string) ($e['id'] ?? '');
        $start  = $this->stamp((string) ($e['starts_at'] ?? ''));
        if ($id === '' || $start === '') {
            return []; // skip malformed rows rather than emit a broken VEVENT
        }
        $endRaw = (string) ($e['ends_at'] ?? '');
        $end    = $endRaw !== '' ? $this->stamp($endRaw) : '';

        $out   = [];
        $out[] = 'BEGIN:VEVENT';
        $out[] = 'UID:' . $id . '@' . $host;
        $out[] = 'DTSTAMP:' . $stamp;
        $out[] = 'DTSTART:' . $start;
        if ($end !== '') {
            $out[] = 'DTEND:' . $end;
        }
        $out[] = 'SUMMARY:' . $this->escapeText((string) ($e['title'] ?? ''));

        $desc = trim((string) ($e['description'] ?? ''));
        if ($desc !== '') {
            $out[] = 'DESCRIPTION:' . $this->escapeText($desc);
        }

        // Location: a virtual join link, else "Online"/"In person" hint by mode.
        $access = trim((string) ($e['access_url'] ?? ''));
        $mode   = (string) ($e['mode'] ?? '');
        if ($access !== '') {
            $out[] = 'LOCATION:' . $this->escapeText($access);
        } elseif ($mode === 'virtual') {
            $out[] = 'LOCATION:Online';
        }

        // Canonical event page URL (never a secret) when we know the base.
        if ($baseUrl !== '') {
            $out[] = 'URL:' . $this->escapeText($baseUrl . '/events/' . $id);
        }

        // Cancelled events (if the caller included them) tell clients to drop it.
        if ((string) ($e['status'] ?? '') === 'cancelled') {
            $out[] = 'STATUS:CANCELLED';
        } else {
            $out[] = 'STATUS:CONFIRMED';
        }

        // SEQUENCE from updated_at so an edited event supersedes the cached copy.
        $out[] = 'SEQUENCE:' . $this->sequence((string) ($e['updated_at'] ?? ''));

        $out[] = 'END:VEVENT';

        return $out;
    }

    /**
     * Convert a stored "YYYY-MM-DD HH:MM:SS" (UTC) into an iCal UTC stamp
     * "YYYYMMDDTHHMMSSZ". Returns '' when the input is not a usable datetime.
     */
    private function stamp(string $sqlUtc): string
    {
        $sqlUtc = trim($sqlUtc);
        if ($sqlUtc === '') {
            return '';
        }
        // Accept "YYYY-MM-DD HH:MM:SS" or "...T...".
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $sqlUtc, $m)) {
            return '';
        }

        return $m[1] . $m[2] . $m[3] . 'T' . $m[4] . $m[5] . ($m[6] ?? '00') . 'Z';
    }

    /** Monotonic-ish SEQUENCE derived from an updated_at timestamp (0 when unknown). */
    private function sequence(string $updatedAt): string
    {
        $updatedAt = trim($updatedAt);
        if ($updatedAt === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $updatedAt)) {
            return '0';
        }
        $ts = strtotime($updatedAt . ' UTC');

        return $ts === false ? '0' : (string) $ts;
    }

    /**
     * Escape a TEXT value per RFC 5545 §3.3.11: backslash, semicolon, comma and
     * newlines. (Colons need not be escaped in TEXT.)
     */
    private function escapeText(string $v): string
    {
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace(["\r\n", "\r", "\n"], '\\n', $v);
        $v = str_replace(';', '\\;', $v);
        $v = str_replace(',', '\\,', $v);

        return $v;
    }

    /**
     * Fold a content line to <=75 OCTETS, continuing with CRLF + a single space
     * (RFC 5545 §3.1). Multibyte-safe: never splits inside a UTF-8 sequence.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out   = '';
        $chunk = '';
        $len   = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            // Would adding this byte exceed 75? (first line 75, continuations 74
            // because of the leading space). Keep it simple: cap raw chunk at 73
            // to leave room and avoid splitting a multibyte char.
            if (strlen($chunk) >= 73 && (ord($c) & 0xC0) !== 0x80) {
                $out  .= ($out === '' ? '' : "\r\n ") . $chunk;
                $chunk = '';
            }
            $chunk .= $c;
        }
        if ($chunk !== '') {
            $out .= ($out === '' ? '' : "\r\n ") . $chunk;
        }

        return $out;
    }
}
