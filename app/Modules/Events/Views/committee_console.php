<?php
/**
 * EVENT COMMITTEE console (GET /events/{id}/committee) — the browser face of
 * CommitteeController::console.
 *
 * One page for the whole governance side of an event's committee:
 *   - the mandate, the oversight group, the authority window and the plan's progress;
 *   - the members, each with their specific responsibility AND the delegation that
 *     carries it (so "who may do what, until when" is visible, not implied);
 *   - the lanes nobody holds yet;
 *   - the decisions waiting on the group leader, with the maker-checker forms;
 *   - how this group has configured committee oversight (hierarchical group config);
 *   - and a no-JS PRG form for every governance act: form, appoint member, appoint
 *     chair, change a lane, remove/resign, record a decision, dissolve.
 *
 * When the group has not enabled the `event_committee` capability the page says so
 * and offers nothing to fill in — the feature is DEFAULT OFF and an event with no
 * committee behaves exactly as before.
 *
 * SELF-CONTAINED: includes _locale.php for a locale-aware <html lang dir> (RTL for
 * Arabic), copy is localized via lang('Events.committee.*') with English fallback,
 * ids/names are server data shown escaped. No JavaScript, no external assets (CSP).
 *
 * @var string                    $eventId
 * @var array<string,mixed>|null  $event      events row
 * @var array<string,mixed>|null  $committee  decorated committee, or null
 * @var bool                      $enabled    the group capability is on
 * @var array<string,mixed>       $config     resolved event_committee settings
 * @var list<array<string,mixed>> $roster     people to appoint (id, display_name)
 * @var list<array<string,mixed>> $oversight  oversight group choices (id, name, distance)
 * @var list<array<string,mixed>> $responsibilities  {value,label,permission}
 * @var list<array<string,mixed>> $decisions  this committee's decisions, newest first
 * @var array<string,mixed>       $progress   plan roll-up {progress_pct,health,tasks,milestones}
 * @var array<string,mixed>       $attention  late/due-soon/blocked/unassigned lists
 * @var list<array<string,mixed>> $myTasks    the actor's open tasks on this plan
 * @var list<string>              $kinds      decision kinds
 * @var list<string>              $effects    effect actions ('none' first)
 * @var string                    $actorId
 * @var string                    $csrf
 */
$eventId          = $eventId ?? '';
$event            = $event ?? null;
$committee        = $committee ?? null;
$enabled          = (bool) ($enabled ?? false);
$config           = $config ?? [];
$roster           = $roster ?? [];
$oversight        = $oversight ?? [];
$responsibilities = $responsibilities ?? [];
$decisions        = $decisions ?? [];
$progress         = $progress ?? [];
$attention        = $attention ?? [];
$myTasks          = $myTasks ?? [];
$kinds            = $kinds ?? [];
$effects          = $effects ?? [];
$actorId          = $actorId ?? '';
$csrf             = $csrf ?? '';

$sidAttr   = $eventId !== '' ? rawurlencode($eventId) : '';
$planUrl   = base_url('/events/' . $sidAttr . '/plan');
$selfUrl   = base_url('/events/' . $sidAttr . '/committee');
$hubUrl    = base_url('/event-committees');
$hasCommittee = is_array($committee);
$active       = $hasCommittee ? (array) ($committee['active_members'] ?? []) : [];
$eventStatus  = (string) ($event['status'] ?? '');
$canForm      = $enabled && ! $hasCommittee && in_array($eventStatus, ['draft', 'published'], true);

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

// The authority window: the latest effective_to among active members.
$windowTo = null;
foreach ($active as $m) {
    $to = (string) ($m['effective_to'] ?? '');
    if ($to !== '' && ($windowTo === null || $to > $windowTo)) {
        $windowTo = $to;
    }
}
$pending = (int) ($committee['pending_decisions'] ?? 0);
$coverage = (array) ($committee['responsibilities'] ?? ['filled' => [], 'vacant' => [], 'by_responsibility' => []]);
$respLabel = static function (string $r) use ($responsibilities): string {
    foreach ($responsibilities as $opt) {
        if ((string) ($opt['value'] ?? '') === $r) {
            return (string) ($opt['label'] ?? $r);
        }
    }

    return lang('Events.committee.resp' . ucfirst($r));
};
$health = (string) ($progress['health'] ?? 'on_track');
$taskRollup = (array) ($progress['tasks'] ?? []);
?>

<?php ob_start(); ?>
<?= esc(lang('Events.committee.heading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        h3 { font-size:.98rem; margin:0 0 12px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        .pill.dissolved { border-color:#7f1d1d; color:#fca5a5; }


        .bar { flex:1 1 160px; min-width:140px; height:10px; background:#1e293b; border-radius:999px; overflow:hidden; }


        .bar > i { display:block; height:100%; background:#0d9488; }


        button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#0d9488; color:#fff; }


        button.tiny { margin:0; padding:5px 10px; font-size:.78rem; }


        dl { display:grid; grid-template-columns:max-content 1fr; gap:4px 14px; margin:0; font-size:.88rem; }


        dt { color:#64748b; }


        dd { margin:0; }


        .notice { border-color:#a16207; background:#1c1608; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.committee.heading')) ?></h1>
        <p class="sub">
            <?= esc((string) ($event['title'] ?? lang('Events.eventFallback'))) ?>
            <span class="pill <?= esc($eventStatus, 'attr') ?>"><?= esc($eventStatus !== '' ? lang('Events.status.' . $eventStatus) : '—') ?></span>
            <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span>
            · <a href="<?= esc($planUrl, 'attr') ?>"><?= esc(lang('Events.committee.openPlan')) ?></a>
            · <a href="<?= esc($hubUrl, 'attr') ?>"><?= esc(lang('Events.decision.heading')) ?></a>
        </p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if (! $enabled): ?>
            <div class="card notice">
                <h3><?= esc(lang('Events.committee.heading')) ?></h3>
                <p class="muted" style="margin:0"><?= esc(lang('Events.committee.gatedOff')) ?></p>
            </div>
        <?php elseif (! $hasCommittee): ?>
            <div class="card">
                <p class="muted"><?= esc(lang('Events.committee.noCommittee')) ?></p>
                <?php if ($canForm): ?>
                    <form method="post" action="<?= esc($selfUrl, 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <h3><?= esc(lang('Events.committee.formHeading')) ?></h3>
                        <div class="grid">
                            <div class="full">
                                <label for="mandate"><?= esc(lang('Events.committee.mandateLbl')) ?></label>
                                <textarea id="mandate" name="mandate" rows="2" maxlength="500" required></textarea>
                                <p class="muted" style="margin:4px 0 0;font-size:.78rem"><?= esc(lang('Events.committee.mandateHelp')) ?></p>
                            </div>
                            <div>
                                <label for="chair"><?= esc(lang('Events.committee.chairLbl')) ?></label>
                                <select id="chair" name="chair_user_id">
                                    <option value="">—</option>
                                    <?php foreach ($roster as $u): ?>
                                        <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="muted" style="margin:4px 0 0;font-size:.78rem"><?= esc(lang('Events.committee.chairHelp')) ?></p>
                            </div>
                            <div>
                                <label for="oversight"><?= esc(lang('Events.committee.oversightGroupLbl')) ?></label>
                                <select id="oversight" name="oversight_group_id">
                                    <?php foreach ($oversight as $g): ?>
                                        <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>"><?= esc((string) ($g['name'] ?? $g['id'] ?? '')) ?><?= (int) ($g['distance'] ?? 0) > 0 ? ' (+' . (int) $g['distance'] . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="muted" style="margin:4px 0 0;font-size:.78rem"><?= esc(lang('Events.committee.oversightHelp')) ?></p>
                            </div>
                        </div>
                        <button type="submit"><?= esc(lang('Events.committee.formBtn')) ?></button>
                    </form>
                <?php else: ?>
                    <p class="empty"><?= esc(lang('Events.committee.errBadEventState')) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Committee header -->
            <div class="card">
                <div class="head">
                    <span class="pill <?= esc((string) ($committee['status'] ?? ''), 'attr') ?>"><?= esc((string) ($committee['status'] ?? '') === 'active' ? lang('Events.committee.statusActive') : lang('Events.committee.statusDissolved')) ?></span>
                    <strong><?= $li('Events.committee.memberCountFmt', (string) count($active)) ?></strong>
                    <span class="muted"><?= esc(lang('Events.plan.progressLbl')) ?></span>
                    <span class="bar" role="img" aria-label="<?= esc(lang('Events.plan.progressLbl')) ?>"><i style="width: <?= (int) ($progress['progress_pct'] ?? 0) ?>%"></i></span>
                    <strong><?= (int) ($progress['progress_pct'] ?? 0) ?>%</strong>
                    <span class="pill <?= esc($health, 'attr') ?>"><?= esc(lang('Events.plan.health' . str_replace('_', '', ucwords($health, '_')))) ?></span>
                    <?php if ($windowTo !== null): ?>
                        <span class="muted"><?= $li('Events.committee.windowFmt', substr((string) $windowTo, 0, 10)) ?></span>
                    <?php endif; ?>
                    <a href="<?= esc($planUrl, 'attr') ?>" style="margin-inline-start:auto"><?= esc(lang('Events.committee.openPlan')) ?> →</a>
                </div>
                <dl style="margin-top:14px">
                    <dt><?= esc(lang('Events.committee.mandateHeading')) ?></dt>
                    <dd><?= esc((string) ($committee['mandate'] ?? '')) ?></dd>
                    <dt><?= esc(lang('Events.committee.chairLbl')) ?></dt>
                    <dd><?= esc((string) ($committee['chair_name'] ?? '—')) ?> <span class="mono"><?= esc((string) ($committee['chair_user_id'] ?? '')) ?></span></dd>
                    <dt><?= esc(lang('Events.committee.oversightGroupLbl')) ?></dt>
                    <dd><span class="mono"><?= esc((string) ($committee['oversight_group_id'] ?? '—')) ?></span></dd>
                    <dt><?= esc(lang('Events.committee.configOversight')) ?></dt>
                    <dd><?= esc(lang('Events.committee.oversight' . str_replace('_', '', ucwords((string) ($committee['oversight_mode'] ?? 'formation_and_major'), '_')))) ?></dd>
                </dl>
            </div>

            <!-- Members -->
            <h2><?= esc(lang('Events.committee.membersLbl')) ?></h2>
            <?php if (($committee['members'] ?? []) === []): ?>
                <p class="empty"><?= esc(lang('Events.committee.noMembers')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr>
                        <th><?= esc(lang('Events.committee.colMember')) ?></th>
                        <th><?= esc(lang('Events.committee.colResponsibility')) ?></th>
                        <th><?= esc(lang('Events.committee.colAuthority')) ?></th>
                        <th><?= esc(lang('Events.committee.colWindow')) ?></th>
                        <th><?= esc(lang('Events.committee.colStatus')) ?></th>
                        <th><?= esc(lang('Events.committee.colActions')) ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ((array) ($committee['members'] ?? []) as $m):
                            $mid = (string) ($m['id'] ?? '');
                            $status = (string) ($m['status'] ?? '');
                            $perm = $m['delegated_permission'] ?? null;
                            $isActive = $status === 'active'; ?>
                            <tr>
                                <td>
                                    <?= esc((string) ($m['user_name'] ?? $m['user_id'] ?? '—')) ?>
                                    <?php if ((int) ($m['is_chair'] ?? 0) === 1): ?><span class="pill active"><?= esc(lang('Events.committee.isChair')) ?></span><?php endif; ?>
                                    <div class="mono"><?= esc((string) ($m['user_id'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <?= esc($respLabel((string) ($m['responsibility'] ?? 'general'))) ?>
                                    <?php if (! empty($m['responsibility_label'])): ?><div class="muted" style="font-size:.8rem"><?= esc((string) $m['responsibility_label']) ?></div><?php endif; ?>
                                    <?php if ($isActive): ?>
                                        <form method="post" action="<?= esc(base_url('/event-committees/member/' . rawurlencode($mid) . '/responsibility'), 'attr') ?>" class="row" style="margin-top:6px">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <select name="responsibility" aria-label="<?= esc(lang('Events.committee.responsibilityLbl')) ?>" style="width:auto">
                                                <?php foreach ($responsibilities as $opt): ?>
                                                    <option value="<?= esc((string) $opt['value'], 'attr') ?>"<?= (string) ($m['responsibility'] ?? '') === (string) $opt['value'] ? ' selected' : '' ?>><?= esc((string) $opt['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="ghost tiny"><?= esc(lang('Events.committee.changeRespBtn')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($perm !== null && $perm !== ''): ?>
                                        <span class="mono"><?= esc((string) $perm) ?></span>
                                        <?php if ($isActive && empty($m['delegation_id'])): ?>
                                            <div class="pill removed"><?= esc(lang('Events.committee.authorityFailed')) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted"><?= esc(lang('Events.committee.noAuthority')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono"><?= esc(substr((string) ($m['effective_to'] ?? ''), 0, 10)) ?></td>
                                <td><span class="pill <?= esc($status, 'attr') ?>"><?= esc(lang('Events.committee.member' . ucfirst($status))) ?></span></td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <form method="post" action="<?= esc(base_url('/event-committees/member/' . rawurlencode($mid) . '/remove'), 'attr') ?>">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <input type="hidden" name="reason" value="<?= (string) ($m['user_id'] ?? '') === $actorId ? 'resigned' : 'removed' ?>">
                                            <button type="submit" class="ghost tiny danger"><?= esc((string) ($m['user_id'] ?? '') === $actorId ? lang('Events.committee.resignBtn') : lang('Events.committee.removeBtn')) ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ((string) ($committee['status'] ?? '') === 'active'): ?>
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start">
                    <form class="card" method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/committee/members'), 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <h3><?= esc(lang('Events.committee.addMemberHeading')) ?></h3>
                        <div class="grid">
                            <div>
                                <label for="m-user"><?= esc(lang('Events.committee.memberLbl')) ?></label>
                                <select id="m-user" name="user_id" required>
                                    <option value="">—</option>
                                    <?php foreach ($roster as $u): ?>
                                        <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="m-resp"><?= esc(lang('Events.committee.responsibilityLbl')) ?></label>
                                <select id="m-resp" name="responsibility">
                                    <?php foreach ($responsibilities as $opt): ?>
                                        <option value="<?= esc((string) $opt['value'], 'attr') ?>"><?= esc((string) $opt['label']) ?><?= $opt['permission'] ? ' · ' . esc((string) $opt['permission']) : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="full">
                                <label for="m-label"><?= esc(lang('Events.committee.labelLbl')) ?></label>
                                <input id="m-label" name="label" maxlength="120">
                                <p class="muted" style="margin:4px 0 0;font-size:.78rem"><?= esc(lang('Events.committee.labelHelp')) ?></p>
                            </div>
                        </div>
                        <button type="submit"><?= esc(lang('Events.committee.addMemberBtn')) ?></button>
                    </form>

                    <form class="card" method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/committee/chair'), 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <h3><?= esc(lang('Events.committee.appointChairHeading')) ?></h3>
                        <div class="grid">
                            <div class="full">
                                <label for="c-user"><?= esc(lang('Events.committee.chairLbl')) ?></label>
                                <select id="c-user" name="user_id" required>
                                    <option value="">—</option>
                                    <?php foreach ($roster as $u): ?>
                                        <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"<?= (string) ($committee['chair_user_id'] ?? '') === (string) ($u['id'] ?? '') ? ' selected' : '' ?>><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="full">
                                <label for="c-reason"><?= esc(lang('Events.decision.noteLbl')) ?></label>
                                <input id="c-reason" name="reason" maxlength="200">
                            </div>
                        </div>
                        <button type="submit"><?= esc(lang('Events.committee.appointChairBtn')) ?></button>
                    </form>
                </div>

                <!-- Vacant lanes -->
                <?php $vacant = (array) ($coverage['vacant'] ?? []); ?>
                <h2><?= esc(lang('Events.committee.vacantHeading')) ?></h2>
                <?php if ($vacant === []): ?>
                    <p class="empty"><?= esc(lang('Events.committee.vacantNone')) ?></p>
                <?php else: ?>
                    <p class="row">
                        <?php foreach ($vacant as $v): ?>
                            <span class="pill"><?= esc($respLabel((string) $v)) ?></span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>

                <!-- Oversight queue -->
                <h2><?= esc(lang('Events.committee.decisionsHeading')) ?></h2>
                <?php if ($pending > 0): ?>
                    <p class="sub"><?= $li('Events.committee.pendingFmt', (string) $pending) ?> · <a href="<?= esc(base_url('/event-committees/decisions'), 'attr') ?>"><?= esc(lang('Events.committee.openQueue')) ?> →</a></p>
                <?php else: ?>
                    <p class="empty"><?= esc(lang('Events.committee.nonePending')) ?></p>
                <?php endif; ?>
                <?php if ($decisions !== []): ?>
                    <table>
                        <thead><tr>
                            <th><?= esc(lang('Events.decision.colKind')) ?></th>
                            <th><?= esc(lang('Events.decision.colTitle')) ?></th>
                            <th><?= esc(lang('Events.decision.colRequired')) ?></th>
                            <th><?= esc(lang('Events.decision.colRequestedBy')) ?></th>
                            <th><?= esc(lang('Events.decision.colStatus')) ?></th>
                            <th><?= esc(lang('Events.decision.colActions')) ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($decisions as $d):
                                $did = (string) ($d['id'] ?? '');
                                $dStatus = (string) ($d['status'] ?? ''); ?>
                                <tr>
                                    <td><?= esc(lang('Events.decision.kind' . ucfirst((string) ($d['kind'] ?? 'other')))) ?></td>
                                    <td>
                                        <?= esc((string) ($d['title'] ?? '')) ?>
                                        <?php if (! empty($d['amount'])): ?><div class="mono"><?= esc((string) $d['amount']) ?></div><?php endif; ?>
                                        <?php if (! empty($d['detail'])): ?><div class="muted" style="font-size:.8rem"><?= esc((string) $d['detail']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="mono"><?= esc((string) ($d['required_permission'] ?? '—')) ?></td>
                                    <td><?= esc((string) ($d['requested_by_name'] ?? $d['requested_by'] ?? '—')) ?></td>
                                    <td><span class="pill <?= esc($dStatus, 'attr') ?>"><?= esc(lang('Events.decision.status' . ucfirst($dStatus))) ?></span></td>
                                    <td>
                                        <?php if ($dStatus === 'pending'): ?>
                                            <form method="post" action="<?= esc(base_url('/event-committees/decisions/' . rawurlencode($did) . '/approve'), 'attr') ?>" class="row">
                                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                                <input type="hidden" name="return_to" value="committee">
                                                <input type="hidden" name="event_id" value="<?= esc($eventId, 'attr') ?>">
                                                <button type="submit" class="tiny"><?= esc(lang('Events.decision.approveBtn')) ?></button>
                                            </form>
                                            <form method="post" action="<?= esc(base_url('/event-committees/decisions/' . rawurlencode($did) . '/reject'), 'attr') ?>" class="row">
                                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                                <input type="hidden" name="return_to" value="committee">
                                                <input type="hidden" name="event_id" value="<?= esc($eventId, 'attr') ?>">
                                                <input type="text" name="note" maxlength="200" placeholder="<?= esc(lang('Events.decision.noteLbl')) ?>" style="width:auto">
                                                <button type="submit" class="ghost tiny danger"><?= esc(lang('Events.decision.rejectBtn')) ?></button>
                                            </form>
                                            <?php if ((string) ($d['requested_by'] ?? '') === $actorId): ?>
                                                <form method="post" action="<?= esc(base_url('/event-committees/decisions/' . rawurlencode($did) . '/cancel'), 'attr') ?>">
                                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                                    <input type="hidden" name="return_to" value="committee">
                                                    <input type="hidden" name="event_id" value="<?= esc($eventId, 'attr') ?>">
                                                    <button type="submit" class="ghost tiny"><?= esc(lang('Events.decision.cancelBtn')) ?></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="muted"><?= esc(substr((string) ($d['decided_at'] ?? ''), 0, 10)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <form class="card" method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/committee/decisions'), 'attr') ?>">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <h3><?= esc(lang('Events.committee.requestHeading')) ?></h3>
                    <div class="grid">
                        <div>
                            <label for="d-kind"><?= esc(lang('Events.decision.kindLbl')) ?></label>
                            <select id="d-kind" name="kind">
                                <?php foreach ($kinds as $k): ?>
                                    <option value="<?= esc((string) $k, 'attr') ?>"><?= esc(lang('Events.decision.kind' . ucfirst((string) $k))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="d-amount"><?= esc(lang('Events.decision.amountLbl')) ?></label>
                            <input id="d-amount" type="number" step="0.01" min="0" name="amount">
                        </div>
                        <div class="full">
                            <label for="d-title"><?= esc(lang('Events.decision.colTitle')) ?></label>
                            <input id="d-title" name="title" maxlength="200" required>
                        </div>
                        <div class="full">
                            <label for="d-detail"><?= esc(lang('Events.decision.detailLbl')) ?></label>
                            <textarea id="d-detail" name="detail" rows="2" maxlength="1000"></textarea>
                        </div>
                        <div>
                            <label for="d-effect"><?= esc(lang('Events.decision.effectLbl')) ?></label>
                            <select id="d-effect" name="effect_action">
                                <?php foreach ($effects as $e): ?>
                                    <option value="<?= esc((string) $e, 'attr') ?>"><?= esc((string) $e === 'none' ? lang('Events.decision.effectNone') : lang('Events.decision.effect' . str_replace('.', '', ucwords((string) $e, '.')))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="d-effect-user"><?= esc(lang('Events.committee.memberLbl')) ?></label>
                            <select id="d-effect-user" name="effect_user_id">
                                <option value="">—</option>
                                <?php foreach ($roster as $u): ?>
                                    <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="muted" style="margin:8px 0 0;font-size:.8rem"><?= esc(lang('Events.decision.notedHint')) ?></p>
                    <button type="submit"><?= esc(lang('Events.committee.requestBtn')) ?></button>
                </form>

                <!-- Attention + my tasks -->
                <?php $counts = (array) ($attention['counts'] ?? []); ?>
                <h2><?= esc(lang('Events.plan.attentionHeading')) ?></h2>
                <p class="row">
                    <span class="pill late"><?= esc(lang('Events.plan.lateLbl')) ?>: <?= (int) ($counts['late'] ?? 0) ?></span>
                    <span class="pill due_soon"><?= esc(lang('Events.plan.dueSoonLbl')) ?>: <?= (int) ($counts['due_soon'] ?? 0) ?></span>
                    <span class="pill blocked"><?= esc(lang('Events.plan.blockedLbl')) ?>: <?= (int) ($counts['blocked'] ?? 0) ?></span>
                    <span class="pill"><?= esc(lang('Events.plan.unassignedLbl')) ?>: <?= (int) ($counts['unassigned'] ?? 0) ?></span>
                    <span class="pill due_soon"><?= esc(lang('Events.plan.overdueMilestonesLbl')) ?>: <?= (int) ($counts['overdue_milestones'] ?? 0) ?></span>
                </p>
                <?php foreach (['late', 'due_soon', 'blocked'] as $bucket):
                    $rows = (array) ($attention[$bucket] ?? []);
                    if ($rows === []) {
                        continue;
                    } ?>
                    <p class="row" style="margin:6px 0">
                        <span class="pill <?= esc($bucket, 'attr') ?>"><?= esc(lang('Events.plan.' . ($bucket === 'due_soon' ? 'dueSoonLbl' : $bucket . 'Lbl'))) ?></span>
                        <?php foreach ($rows as $t): ?>
                            <span class="muted" style="font-size:.85rem">
                                <?= esc((string) ($t['title'] ?? '')) ?><?= ! empty($t['blocked_reason']) ? ' · ' . esc((string) $t['blocked_reason']) : '' ?>
                            </span>
                        <?php endforeach; ?>
                    </p>
                <?php endforeach; ?>
                <?php if ((int) ($counts['late'] ?? 0) + (int) ($counts['due_soon'] ?? 0) + (int) ($counts['blocked'] ?? 0) === 0): ?>
                    <p class="empty"><?= esc(lang('Events.plan.noneLbl')) ?></p>
                <?php endif; ?>
                <?php if ($myTasks !== []): ?>
                    <h2><?= esc(lang('Events.plan.myTasksHeading')) ?></h2>
                    <table>
                        <thead><tr>
                            <th><?= esc(lang('Events.plan.colTask')) ?></th>
                            <th><?= esc(lang('Events.plan.colDue')) ?></th>
                            <th><?= esc(lang('Events.plan.colStatus')) ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($myTasks as $t): ?>
                                <tr>
                                    <td><?= esc((string) ($t['title'] ?? '')) ?></td>
                                    <td class="mono"><?= esc((string) ($t['due_at'] ?? lang('Events.plan.noDue'))) ?></td>
                                    <td><span class="pill <?= esc((string) ($t['risk'] ?? 'ok'), 'attr') ?>"><?= esc(lang('Events.plan.status' . str_replace('_', '', ucwords((string) ($t['status'] ?? 'todo'), '_')))) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- Group configuration in force -->
                <h2><?= esc(lang('Events.committee.configHeading')) ?></h2>
                <div class="card">
                    <dl>
                        <dt><?= esc(lang('Events.committee.configOversight')) ?></dt>
                        <dd><?= esc(lang('Events.committee.oversight' . str_replace('_', '', ucwords((string) ($config['oversight'] ?? 'formation_and_major'), '_')))) ?></dd>
                        <dt><?= esc(lang('Events.committee.configMaxMembers')) ?></dt>
                        <dd><?= (int) ($config['max_members'] ?? 0) ?></dd>
                        <dt><?= esc(lang('Events.committee.configChairApproval')) ?></dt>
                        <dd><?= esc(! empty($config['chair_requires_approval']) ? lang('Events.committee.configYes') : lang('Events.committee.configNo')) ?></dd>
                        <dt><?= esc(lang('Events.committee.configSubdelegation')) ?></dt>
                        <dd><?= esc(! empty($config['allow_subdelegation']) ? lang('Events.committee.configYes') : lang('Events.committee.configNo')) ?></dd>
                        <dt><?= esc(lang('Events.committee.configGraceDays')) ?></dt>
                        <dd><?= (int) ($config['grace_days'] ?? 0) ?></dd>
                        <dt><?= esc(lang('Events.committee.configThreshold')) ?></dt>
                        <dd><?= isset($config['budget_approval_threshold']) && $config['budget_approval_threshold'] !== null ? esc((string) $config['budget_approval_threshold']) : esc(lang('Events.committee.configNone')) ?></dd>
                        <dt><?= esc(lang('Events.committee.configCrosscut')) ?></dt>
                        <dd><?= esc(! empty($config['allow_crosscut']) ? lang('Events.committee.configYes') : lang('Events.committee.configNo')) ?></dd>
                    </dl>
                </div>

                <!-- Dissolve -->
                <form class="card" method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/committee/dissolve'), 'attr') ?>">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <h3><?= esc(lang('Events.committee.dissolveBtn')) ?></h3>
                    <div class="grid">
                        <div class="full">
                            <label for="dissolve-reason"><?= esc(lang('Events.committee.dissolveReasonLbl')) ?></label>
                            <input id="dissolve-reason" name="reason" maxlength="500" required>
                        </div>
                    </div>
                    <button type="submit" class="danger"><?= esc(lang('Events.committee.dissolveBtn')) ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
