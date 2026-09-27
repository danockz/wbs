<?= $this->extend('layouts/app') ?>

<?php
/**
 * Member self-service dashboard ("my home"). Server-rendered; JSON when
 * negotiated. Everything shown is the authenticated member's OWN data
 * (see MemberDashboardService::forUser — self-scoped).
 *
 * Copy is localized via lang('Reporting.member.*') with English as the
 * guaranteed fallback. Free-form service data stays VERBATIM (it is data, not UI
 * copy): the member display name, badge/achievement/streak/milestone names,
 * event titles + RSVP/mode/timezone, certificate titles/status/verification IDs,
 * course titles/category/status, and group names/role/type/path. {0}/{1}
 * placeholders are interpolated in PHP so no ext-intl is required. Dates are
 * formatted with PHP date() as before.
 *
 * @var array<string,mixed> $result
 */
$profile = $result['profile'] ?? [];
$game    = $result['gamification'] ?? [];
$certs   = $result['certificates'] ?? [];
$events  = $result['events'] ?? [];
$courses = $result['courses'] ?? [];
$groups  = $result['groups'] ?? [];
$milestones = $result['milestones'] ?? ['achieved' => [], 'upcoming' => []];
$meta    = $result['metadata'] ?? [];

$li  = static fn (string $key, string $a): string => str_replace('{0}', $a, lang($key));
$li2 = static fn (string $key, string $a, string $b): string => str_replace(['{0}', '{1}'], [$a, $b], lang($key));

// Localize a milestone label from its track key + tier count. The service still
// emits an English `label` which stays as the guaranteed fallback if a locale
// key is missing (or a new track is added before its copy exists). Plural form
// is picked here from the tier so the choice lives with the language data.
$msLabel = static function (array $m): string {
    $key  = (string) ($m['key'] ?? '');
    $tier = (int) ($m['tier'] ?? 0);
    if ($key === '') {
        return (string) ($m['label'] ?? '');
    }
    $lookup = $key === 'tenure'
        ? 'Reporting.member.ms.tenure'
        : 'Reporting.member.ms.' . $key . ($tier > 1 ? '_other' : '_one');
    $t = lang($lookup);
    // lang() returns the key verbatim on a miss — fall back to the English label.
    if (! is_string($t) || $t === $lookup) {
        return (string) ($m['label'] ?? '');
    }

    return str_replace('{0}', (string) $tier, $t);
};

$stat = static function (string $label, $value, ?string $sub = null): string {
    $v = is_array($value) ? json_encode($value) : (string) $value;
    $s = $sub !== null ? '<div class="muted" style="margin-top:4px">' . esc($sub) . '</div>' : '';

    return '<div class="stat"><div class="k">' . esc($label) . '</div><div class="v">' . esc($v) . '</div>' . $s . '</div>';
};

$fmtDate = static function (?string $dt): string {
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);

    return $ts ? date('M j, Y', $ts) : (string) $dt;
};

$rankName = $game['rank']['name'] ?? lang('Reporting.member.unranked');
$nextRank = $game['next_rank']['name'] ?? null;
$toNext   = $game['points_to_next'] ?? null;
$progress = (int) ($game['progress_pct'] ?? 0);

$badges       = $game['badges'] ?? ['count' => 0, 'items' => []];
$badgeCount   = is_array($badges) ? ($badges['count'] ?? 0) : (int) $badges;
$badgeItems   = is_array($badges) ? ($badges['items'] ?? []) : [];
$achievements = $game['achievements'] ?? ['unlocked_count' => 0, 'unlocked' => [], 'in_progress' => []];
$streaks      = $game['streaks'] ?? [];
?>

<?= $this->section('content') ?>
    <h1><?= esc($li('Reporting.member.welcome', (string) ($profile['display_name'] ?? lang('Reporting.member.memberFallback')))) ?></h1>
    <div class="sub">
        <?= esc(lang('Reporting.member.subtitle')) ?>
        <?php if (! empty($profile['member_since'])): ?>
            · <?= esc($li('Reporting.member.memberSince', $fmtDate($profile['member_since']))) ?>
        <?php endif; ?>
    </div>

    <h2><?= esc(lang('Reporting.member.standing')) ?></h2>
    <div class="grid">
        <?= $stat(lang('Reporting.member.pointsSeason'), $game['points'] ?? 0, isset($game['season_year']) ? $li('Reporting.member.seasonLabel', (string) $game['season_year']) : null) ?>
        <?= $stat(lang('Reporting.member.rank'), $rankName, $nextRank !== null && $toNext !== null ? $li2('Reporting.member.ptsToRank', (string) $toNext, (string) $nextRank) : lang('Reporting.member.topRank')) ?>
        <?= $stat(lang('Reporting.member.badges'), $badgeCount) ?>
        <?= $stat(lang('Reporting.member.achievements'), $achievements['unlocked_count'] ?? 0) ?>
    </div>

    <?php if ($nextRank !== null): ?>
        <div class="card">
            <div class="row">
                <div class="muted"><?= esc($rankName) ?></div>
                <div class="muted"><?= esc($nextRank) ?><?php if ($toNext !== null): ?> (<?= esc($li('Reporting.member.ptsToGo', (string) $toNext)) ?>)<?php endif; ?></div>
            </div>
            <div style="height:8px;background:#1e293b;border-radius:999px;margin-top:8px;overflow:hidden;">
                <div style="height:100%;width:<?= esc($progress) ?>%;background:linear-gradient(90deg,#22d3ee,#a78bfa);"></div>
            </div>
            <div class="muted" style="margin-top:6px;font-size:.72rem;"><?= esc($li('Reporting.member.pctToNext', (string) $progress)) ?></div>
        </div>
    <?php endif; ?>

    <?php $msAchieved = $milestones['achieved'] ?? []; $msUpcoming = $milestones['upcoming'] ?? []; ?>
    <?php if ($msAchieved !== [] || $msUpcoming !== []): ?>
        <h2><?= esc(lang('Reporting.member.milestones')) ?></h2>
        <?php if ($msAchieved !== []): ?>
            <div class="card">
                <div class="k" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;"><?= esc(lang('Reporting.member.reached')) ?></div>
                <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($msAchieved as $m): ?>
                        <span class="pill"><?= esc(($m['icon'] ?? '') . ' ' . $msLabel($m)) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($msUpcoming !== []): ?>
            <div class="card">
                <div class="k" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;"><?= esc(lang('Reporting.member.nextUp')) ?></div>
                <?php foreach ($msUpcoming as $m): ?>
                    <div class="row" style="margin-top:10px;">
                        <div><?= esc(($m['icon'] ?? '') . ' ' . $msLabel($m)) ?></div>
                        <div class="muted"><?= esc($li('Reporting.member.toGo', (string) ($m['remaining'] ?? 0))) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($badgeItems !== [] || ($achievements['in_progress'] ?? []) !== []): ?>
        <h2><?= esc(lang('Reporting.member.badgesAch')) ?></h2>
        <?php if ($badgeItems !== []): ?>
            <div class="card">
                <div class="k" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;"><?= esc(lang('Reporting.member.badgesEarned')) ?></div>
                <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($badgeItems as $b): ?>
                        <span class="pill" title="<?= esc($li('Reporting.member.awardedTitle', $fmtDate($b['awarded_at'] ?? null))) ?>"><?= esc($b['name'] !== '' ? $b['name'] : $b['code']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php $unlocked = $achievements['unlocked'] ?? []; ?>
        <?php if ($unlocked !== []): ?>
            <?php foreach ($unlocked as $a): ?>
                <div class="card">
                    <div class="row">
                        <div class="author">🏅 <?= esc($a['name'] !== '' ? $a['name'] : $a['code']) ?></div>
                        <span class="pill"><?= esc($li('Reporting.member.xp', (string) ($a['xp'] ?? 0))) ?></span>
                    </div>
                    <div class="counts">
                        <?php if (! empty($a['category'])): ?><?= esc($a['category']) ?> · <?php endif; ?>
                        <?= esc($li('Reporting.member.unlockedOn', $fmtDate($a['unlocked_at'] ?? null))) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php $inProgress = $achievements['in_progress'] ?? []; ?>
        <?php if ($inProgress !== []): ?>
            <div class="card">
                <div class="k" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;"><?= esc(lang('Reporting.member.almostThere')) ?></div>
                <?php foreach ($inProgress as $p): ?>
                    <div style="margin-top:12px;">
                        <div class="row">
                            <div><?= esc($p['name'] !== '' ? $p['name'] : $p['code']) ?></div>
                            <div class="muted"><?= esc((int) round($p['current'] ?? 0)) ?>/<?= esc((int) round($p['required'] ?? 0)) ?></div>
                        </div>
                        <div style="height:6px;background:#1e293b;border-radius:999px;margin-top:6px;overflow:hidden;">
                            <div style="height:100%;width:<?= esc((int) round($p['percentage'] ?? 0)) ?>%;background:#818cf8;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($streaks !== []): ?>
        <h2><?= esc(lang('Reporting.member.streaks')) ?></h2>
        <div class="grid">
            <?php foreach ($streaks as $s): ?>
                <?= $stat(
                    '🔥 ' . ($s['name'] !== '' ? $s['name'] : $s['code']),
                    $s['current_count'] ?? 0,
                    $li('Reporting.member.best', (string) ($s['best_count'] ?? 0)) . ($s['cadence'] ? ' · ' . $s['cadence'] : ''),
                ) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2><?= esc(lang('Reporting.member.upcomingEvents')) ?></h2>
    <?php if ($events === []): ?>
        <div class="empty"><?= esc(lang('Reporting.member.noEvents')) ?> <a href="/events"><?= esc(lang('Reporting.member.browseEvents')) ?></a></div>
    <?php else: ?>
        <?php foreach ($events as $e): ?>
            <div class="card">
                <div class="row">
                    <div class="author"><?= esc($e['title']) ?></div>
                    <span class="pill"><?= esc($e['rsvp_state'] ?? $e['reg_status'] ?? '') ?></span>
                </div>
                <div class="counts">
                    <?= esc($fmtDate($e['starts_at'])) ?> · <?= esc($e['mode'] ?? '') ?>
                    <?php if (! empty($e['timezone'])): ?> · <?= esc($e['timezone']) ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2><?= esc(lang('Reporting.member.certificates')) ?></h2>
    <?php if ($certs === []): ?>
        <div class="empty"><?= esc(lang('Reporting.member.noCerts')) ?></div>
    <?php else: ?>
        <?php foreach ($certs as $c): ?>
            <div class="card">
                <div class="row">
                    <div class="author"><?= esc($c['event_title'] !== '' ? $c['event_title'] : lang('Reporting.member.certFallback')) ?></div>
                    <span class="pill"><?= esc($c['status']) ?></span>
                </div>
                <div class="counts">
                    <?= esc($li('Reporting.member.issued', $fmtDate($c['issued_at']))) ?>
                    · <?= esc(lang('Reporting.member.verifyId')) ?> <code><?= esc($c['verification_id']) ?></code>
                    <?php if (! empty($c['download_ref'])): ?>
                        · <a href="/certificates/<?= esc($c['id']) ?>/download"><?= esc(lang('Reporting.member.downloadPdf')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2><?= esc(lang('Reporting.member.learning')) ?></h2>
    <div class="grid">
        <?= $stat(lang('Reporting.member.activeCourses'), $courses['active'] ?? 0) ?>
        <?= $stat(lang('Reporting.member.completed'), $courses['completed'] ?? 0) ?>
    </div>
    <?php $items = $courses['items'] ?? []; ?>
    <?php if ($items !== []): ?>
        <?php foreach ($items as $c): ?>
            <div class="card">
                <div class="row">
                    <div class="author"><?= esc($c['title'] !== '' ? $c['title'] : lang('Reporting.member.courseFallback')) ?></div>
                    <span class="pill"><?= esc($c['status']) ?></span>
                </div>
                <div class="counts">
                    <?php if (! empty($c['category'])): ?><?= esc($c['category']) ?> · <?php endif; ?>
                    <?php if ($c['status'] === 'completed'): ?>
                        <?= esc($li('Reporting.member.completedOn', $fmtDate($c['completed_at']))) ?>
                    <?php else: ?>
                        <?= esc($li('Reporting.member.enrolledOn', $fmtDate($c['enrolled_at']))) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2><?= esc(lang('Reporting.member.groups')) ?></h2>
    <?php if ($groups === []): ?>
        <div class="empty"><?= esc(lang('Reporting.member.noGroups')) ?></div>
    <?php else: ?>
        <?php foreach ($groups as $g): ?>
            <div class="card">
                <div class="row">
                    <div class="author"><?= esc($g['name']) ?></div>
                    <span class="pill"><?= esc($g['role']) ?></span>
                </div>
                <?php if (! empty($g['group_path'])): ?>
                    <div class="counts"><?= esc(implode(' › ', $g['group_path'])) ?></div>
                <?php endif; ?>
                <div class="counts">
                    <?php if (! empty($g['type'])): ?><?= esc($g['type']) ?> · <?php endif; ?>
                    <?= esc($li('Reporting.member.joined', $fmtDate($g['joined_at']))) ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="meta">
        <?= esc($li('Reporting.member.metaSelfScoped', (string) ($meta['as_of'] ?? ''))) ?>
    </div>
<?= $this->endSection() ?>
