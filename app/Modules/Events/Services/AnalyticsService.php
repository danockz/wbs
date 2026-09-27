<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

/**
 * Cross-event ANALYTICS — an organizer's birds-eye across the whole events
 * programme, complementing the per-event ReportService (which drills into ONE
 * event) and the flat events index (which just lists them).
 *
 * Answers the programme questions a single-event report can't: How many events
 * are upcoming vs past? How many people register, show up, and what's the
 * RSVP→check-in conversion? Which events are filling up (fill rate vs capacity)?
 * How much paid-ticket revenue has been collected? Where is attendance trending?
 *
 * RESOURCE-LIGHT (standing constraint): the whole dashboard is a FIXED, small
 * number of grouped-aggregate reads regardless of how many events, registrations
 * or attendees exist — never a per-event fan-out. Registrations and attendance
 * are each ONE grouped-by-event COUNT folded in memory; revenue is ONE SUM;
 * status/mode splits are ONE grouped COUNT each. Scope is org-wide, optionally
 * narrowed to an organizing group.
 */
final class AnalyticsService
{
    /** Statuses that mean an event has run (for the "past/attended" lens). */
    private const RUN_STATUSES = ['completed', 'completed_no_attendance'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The full dashboard payload for a context.
     *
     * @param int $upcomingLimit how many upcoming events to list (bounded)
     * @param int $fillLimit     how many "filling up" events to list (bounded)
     * @return Result data: {
     *   group_id, now,
     *   totals: {events,upcoming,past,draft,published,cancelled,attended_events},
     *   people: {registrations,waitlisted,cancelled_regs,attendance,unique_attendees,
     *            conversion_pct,show_rate_pct,no_show},
     *   revenue: {paid_orders,gross_minor,currency},
     *   by_mode: [{mode,count}],
     *   by_status: [{status,count}],
     *   upcoming: [{id,title,starts_at,capacity,registered,fill_pct,mode}],
     *   filling: [{id,title,starts_at,capacity,registered,fill_pct}],
     * }
     */
    public function dashboard(string $organizationId, ?string $groupId = null, int $upcomingLimit = 8, int $fillLimit = 6): Result
    {
        $upcomingLimit = max(1, min(50, $upcomingLimit));
        $fillLimit     = max(1, min(50, $fillLimit));
        $now           = $this->clock->nowUtcString();

        // ---- Events: pull the light columns ONCE, split in memory. ----------
        $evQ = $this->db->table('events')
            ->select('id, title, status, mode, starts_at, capacity')
            ->where('organization_id', $organizationId)
            // L4 — archived events are filed away; the programme dashboard counts
            // only live events (they stay reachable via the archive list).
            ->where('archived_at IS NULL', null, false);
        if ($groupId !== null && $groupId !== '') {
            $evQ->where('group_id', $groupId);
        }
        $events = $evQ->orderBy('starts_at', 'ASC')->get()->getResultArray();

        $eventIds  = [];
        $byMode    = [];
        $byStatus  = [];
        $totals    = [
            'events' => 0, 'upcoming' => 0, 'past' => 0, 'draft' => 0,
            'published' => 0, 'cancelled' => 0, 'attended_events' => 0,
        ];
        foreach ($events as $e) {
            $id           = (string) $e['id'];
            $eventIds[]   = $id;
            $status       = (string) ($e['status'] ?? '');
            $mode         = (string) ($e['mode'] ?? '');
            $starts       = (string) ($e['starts_at'] ?? '');
            $totals['events']++;
            $byMode[$mode]     = ($byMode[$mode] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            if (isset($totals[$status])) {
                $totals[$status]++;
            }
            if (in_array($status, self::RUN_STATUSES, true)) {
                $totals['attended_events']++;
            }
            // Upcoming = not cancelled and starts in the future.
            if ($status !== 'cancelled' && $starts !== '' && $starts >= $now) {
                $totals['upcoming']++;
            } elseif ($starts !== '' && $starts < $now) {
                $totals['past']++;
            }
        }

        // ---- Registrations by event + status (ONE grouped read). ------------
        $regByEvent   = [];   // event_id => confirmed registered count
        $registrations = 0;
        $waitlisted    = 0;
        $cancelledRegs = 0;
        if ($eventIds !== []) {
            foreach (
                $this->db->table('event_registrations')
                    ->select('event_id, status, COUNT(*) AS n')
                    ->where('organization_id', $organizationId)
                    ->whereIn('event_id', $eventIds)
                    ->groupBy('event_id, status')
                    ->get()->getResultArray() as $row
            ) {
                $st = (string) $row['status'];
                $n  = (int) $row['n'];
                if ($st === 'registered') {
                    $registrations             += $n;
                    $regByEvent[(string) $row['event_id']] = ($regByEvent[(string) $row['event_id']] ?? 0) + $n;
                } elseif ($st === 'waitlisted') {
                    $waitlisted += $n;
                } elseif ($st === 'cancelled') {
                    $cancelledRegs += $n;
                }
            }
        }

        // ---- Follow-up-sourced registrations (ONE bounded COUNT). -----------
        // Registrations created by an outreach follow-up carry source_ref
        // "contact:{id}". Counting them lets organizers see how much of the
        // roster came from members/leaders/staff working their contacts. One
        // indexed count, independent of roster size (resource-light).
        $followUpSourced = 0;
        if ($eventIds !== []) {
            $followUpSourced = (int) $this->db->table('event_registrations')
                ->where('organization_id', $organizationId)
                ->whereIn('event_id', $eventIds)
                ->where('status !=', 'cancelled')
                ->like('source_ref', 'contact:', 'after')
                ->countAllResults();
        }

        // ---- Attendance (ONE grouped read): present rows only. --------------
        $attendance      = 0;
        $uniqueAttendees = [];
        if ($eventIds !== []) {
            foreach (
                $this->db->table('event_attendance')
                    ->select('user_id, COUNT(*) AS n')
                    ->where('organization_id', $organizationId)
                    ->whereIn('event_id', $eventIds)
                    ->where('status', 'present')
                    ->groupBy('user_id')
                    ->get()->getResultArray() as $row
            ) {
                $attendance += (int) $row['n'];
                $uniqueAttendees[(string) $row['user_id']] = true;
            }
        }

        // ---- Revenue (ONE SUM over paid orders). ----------------------------
        $paidOrders = 0;
        $grossMinor = 0;
        $currency   = 'GHS';
        if ($eventIds !== []) {
            $rev = $this->db->table('event_orders')
                ->select('COUNT(*) AS orders, SUM(total_minor) AS gross, MAX(currency) AS ccy')
                ->where('organization_id', $organizationId)
                ->whereIn('event_id', $eventIds)
                ->where('status', 'paid')
                ->get()->getRowArray();
            if ($rev !== null) {
                $paidOrders = (int) ($rev['orders'] ?? 0);
                $grossMinor = (int) ($rev['gross'] ?? 0);
                $currency   = (string) ($rev['ccy'] ?? 'GHS') ?: 'GHS';
            }
        }

        // ---- Derived people metrics. ----------------------------------------
        $conversionPct = $registrations > 0 ? round($attendance * 100 / $registrations, 1) : 0.0;
        $noShow        = max(0, $registrations - $attendance);
        // Show-rate is the same ratio expressed as the share that turned up.
        $showRatePct   = $conversionPct;

        // ---- Upcoming list + fill rate (in-memory over the light rows). -----
        $upcoming = [];
        $filling  = [];
        foreach ($events as $e) {
            $id      = (string) $e['id'];
            $status  = (string) ($e['status'] ?? '');
            $starts  = (string) ($e['starts_at'] ?? '');
            $cap     = ($e['capacity'] ?? null) !== null ? (int) $e['capacity'] : null;
            $reg     = $regByEvent[$id] ?? 0;
            $fillPct = ($cap !== null && $cap > 0) ? round($reg * 100 / $cap, 1) : null;
            $rowOut  = [
                'id'         => $id,
                'title'      => (string) ($e['title'] ?? ''),
                'starts_at'  => $starts,
                'capacity'   => $cap,
                'registered' => $reg,
                'fill_pct'   => $fillPct,
                'mode'       => (string) ($e['mode'] ?? ''),
            ];
            if ($status !== 'cancelled' && $starts !== '' && $starts >= $now) {
                $upcoming[] = $rowOut;
                if ($fillPct !== null) {
                    $filling[] = $rowOut;
                }
            }
        }
        // Upcoming: soonest first (already ASC). Filling: fullest first.
        usort($filling, static fn ($a, $b) => ($b['fill_pct'] ?? 0) <=> ($a['fill_pct'] ?? 0));
        $upcoming = array_slice($upcoming, 0, $upcomingLimit);
        $filling  = array_slice($filling, 0, $fillLimit);

        // Sort the split lists deterministically (largest first) for display.
        $modeList = [];
        foreach ($byMode as $mode => $count) {
            $modeList[] = ['mode' => $mode, 'count' => $count];
        }
        usort($modeList, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $statusList = [];
        foreach ($byStatus as $st => $count) {
            $statusList[] = ['status' => $st, 'count' => $count];
        }
        usort($statusList, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return Result::ok([
            'group_id' => $groupId,
            'now'      => $now,
            'totals'   => $totals,
            'people'   => [
                'registrations'    => $registrations,
                'waitlisted'       => $waitlisted,
                'cancelled_regs'   => $cancelledRegs,
                'attendance'       => $attendance,
                'unique_attendees' => count($uniqueAttendees),
                'conversion_pct'   => $conversionPct,
                'show_rate_pct'    => $showRatePct,
                'no_show'          => $noShow,
                'follow_up_sourced' => $followUpSourced,
            ],
            'revenue' => [
                'paid_orders' => $paidOrders,
                'gross_minor' => $grossMinor,
                'currency'    => $currency,
            ],
            'by_mode'   => $modeList,
            'by_status' => $statusList,
            'upcoming'  => $upcoming,
            'filling'   => $filling,
        ]);
    }
}
