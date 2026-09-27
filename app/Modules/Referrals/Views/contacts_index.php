<?php
/**
 * Member/staff ADDRESS BOOK (dashboard) — birds-eye of the downline.
 * Self-contained inline styles (renders in sandboxed previews too).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Referrals.*') with English fallback; counts + singular/plural chosen in
 * PHP. The fixed-vocabulary temperature (hot/warm/cold) is localized with a
 * raw-value fallback. Free-form server vocabularies (journey_stage and
 * decision_type slugs) stay VERBATIM, humanized in-view via ucwords — they are
 * arbitrary service data, not translatable UI copy. All contact data
 * (names/phone/dates) is escaped and rendered verbatim.
 *
 * @var array{total:int,by_temperature:array<string,int>,by_stage:array<string,int>,due:int} $summary
 * @var list<array<string,mixed>> $contacts
 * @var array<string,mixed>       $filters
 * @var string                    $csrf
 * @var list<string>              $temps
 * @var list<string>              $stages
 * @var list<string>              $decisions
 */
$summary   = $summary ?? ['total' => 0, 'by_temperature' => ['hot' => 0, 'warm' => 0, 'cold' => 0], 'by_stage' => [], 'due' => 0];
$contacts  = $contacts ?? [];
$filters   = $filters ?? [];
$temps     = $temps ?? ['hot', 'warm', 'cold'];
$stages    = $stages ?? [];
$decisions = $decisions ?? [];
/** @var list<string> $decisionInputs integration-decision types offered on create (group config, default none) */
$decisionInputs = $decisionInputs ?? [];
$csrf      = $csrf ?? '';
$events    = is_array($events ?? null) ? $events : [];
$courses   = is_array($courses ?? null) ? $courses : [];

include __DIR__ . '/_locale.php';

// PRG flash (error/success) — set humanized at flash time by BaseController
// errText; this view only renders it (FR-ARC-002 sweep).
$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

$tempColor = static fn (string $t): string => match ($t) {
    'hot'  => '#ef4444',
    'warm' => '#f59e0b',
    default => '#38bdf8',
};
// Fixed-vocabulary temperature LABEL localized, with raw-value fallback so an
// unknown warmth never breaks the page. Colour stays code-driven.
$tempLbl = static function (string $t): string {
    if ($t === '') {
        return '';
    }
    $v = lang('Referrals.temperature.' . $t);

    return $v === 'Referrals.temperature.' . $t ? ucfirst($t) : $v;
};
// Humanize a free-form server slug (journey_stage / decision_type). Kept
// verbatim — these are arbitrary lists supplied by the service, not UI copy.
$labelize = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 1080px; margin: 0 auto; padding: 5vh 20px 60px; }


        .dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; vertical-align:middle; }


        .panel h2 { font-size:1.05rem; margin:0 0 14px; }


        .name { font-weight:700; color:#e2e8f0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<?php if ($flashErr !== ''): ?>
        <div style="background:#3f1d1d;border:1px solid #7f1d1d;color:#fecaca;border-radius:10px;padding:12px 16px;margin:14px 0;font-size:.9rem;"><?= esc($flashErr) ?></div>
    <?php endif; ?>
    <?php if ($flashOk !== ''): ?>
        <div style="background:#052e26;border:1px solid #065f46;color:#a7f3d0;border-radius:10px;padding:12px 16px;margin:14px 0;font-size:.9rem;"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <div class="wrap">
        <h1><?= esc(lang('Referrals.heading')) ?></h1>
        <div class="sub"><?= esc(lang('Referrals.sub')) ?></div>

        <div class="tiles">
            <div class="tile"><div class="n"><?= (int) $summary['total'] ?></div><div class="k"><?= esc(lang('Referrals.total')) ?></div></div>
            <?php foreach (['hot', 'warm', 'cold'] as $t): ?>
                <div class="tile">
                    <div class="n"><span class="dot" style="background:<?= $tempColor($t) ?>"></span><?= (int) ($summary['by_temperature'][$t] ?? 0) ?></div>
                    <div class="k"><?= esc($tempLbl($t)) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="tile due"><div class="n"><?= (int) $summary['due'] ?></div><div class="k"><?= esc(lang('Referrals.followUpDue')) ?></div></div>
        </div>

        <nav class="filters">
            <a href="/me/contacts" class="<?= $filters === [] ? 'active' : '' ?>"><?= esc(lang('Referrals.filterAll')) ?></a>
            <a href="/me/contacts?due=1" class="<?= ! empty($filters['due']) ? 'active' : '' ?>"><?= esc(lang('Referrals.filterDue')) ?></a>
            <?php foreach ($temps as $t): ?>
                <a href="/me/contacts?temperature=<?= esc($t) ?>" class="<?= ($filters['temperature'] ?? '') === $t ? 'active' : '' ?>"><?= esc($tempLbl((string) $t)) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="layout">
            <div class="panel">
                <h2><?= count($contacts) ?> <?= esc(count($contacts) === 1 ? lang('Referrals.contact') : lang('Referrals.contacts')) ?></h2>
                <?php if ($contacts === []): ?>
                    <p class="empty"><?= esc(lang('Referrals.emptyContacts')) ?></p>
                <?php else: ?>
                    <table>
                        <thead><tr><th><?= esc(lang('Referrals.colName')) ?></th><th><?= esc(lang('Referrals.colStage')) ?></th><th><?= esc(lang('Referrals.colWarmth')) ?></th><th><?= esc(lang('Referrals.colNextFollow')) ?></th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($contacts as $c): ?>
                            <?php $cid = (string) ($c['id'] ?? ''); $t = (string) ($c['temperature'] ?? 'cold'); ?>
                            <tr>
                                <td>
                                    <div class="name"><?= esc((string) ($c['full_name'] ?? $c['display_name'] ?? lang('Referrals.nameFallback'))) ?></div>
                                    <?php if (! empty($c['phone'])): ?><div class="muted">📞 <?= esc((string) $c['phone']) ?></div><?php endif; ?>
                                    <?php if (! empty($c['address_id'])): ?><div class="muted"><?= esc(lang('Referrals.locationTagged')) ?></div><?php endif; ?>
                                </td>
                                <td><span class="badge"><?= esc($labelize((string) ($c['journey_stage'] ?? 'prospect'))) ?></span></td>
                                <td><span class="dot" style="background:<?= $tempColor($t) ?>"></span><?= esc($tempLbl($t)) ?></td>
                                <td><?= ! empty($c['next_follow_up_at']) ? esc((string) $c['next_follow_up_at']) : '<span class="muted">—</span>' ?></td>
                                <td>
                                    <details class="act">
                                        <summary><?= esc(lang('Referrals.followUp')) ?></summary>
                                        <form method="post" action="/me/contacts/<?= esc($cid) ?>/follow-up">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                            <label><?= esc(lang('Referrals.warmthLbl')) ?></label>
                                            <select name="temperature">
                                                <?php foreach ($temps as $tt): ?>
                                                    <option value="<?= esc($tt) ?>" <?= $tt === $t ? 'selected' : '' ?>><?= esc($tempLbl((string) $tt)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label><?= esc(lang('Referrals.nextFollowLbl')) ?></label>
                                            <input type="date" name="next_follow_up_at">
                                            <button class="btn btn-sm" type="submit"><?= esc(lang('Referrals.logFollowUp')) ?></button>
                                        </form>
                                    </details>
                                    <details class="act">
                                        <summary><?= esc(lang('Referrals.recordDecision')) ?></summary>
                                        <form method="post" action="/me/contacts/<?= esc($cid) ?>/decision">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                            <label><?= esc(lang('Referrals.decisionLbl')) ?></label>
                                            <select name="decision_type">
                                                <?php foreach ($decisions as $d): ?>
                                                    <option value="<?= esc($d) ?>"><?= esc($labelize($d)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label><?= esc(lang('Referrals.dateLbl')) ?></label>
                                            <input type="date" name="decision_date" required>
                                            <button class="btn btn-sm" type="submit"><?= esc(lang('Referrals.record')) ?></button>
                                        </form>
                                    </details>
                                    <details class="act">
                                        <summary><?= esc(lang('Referrals.registerAttendance')) ?></summary>
                                        <form method="post" action="/me/contacts/<?= esc($cid) ?>/attend">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                            <label><?= esc(lang('Referrals.typeLbl')) ?></label>
                                            <select name="type">
                                                <option value="event"><?= esc(lang('Referrals.typeEvent')) ?></option>
                                                <option value="course"><?= esc(lang('Referrals.typeCourse')) ?></option>
                                            </select>
                                            <label><?= esc(lang('Referrals.eventCourseIdLbl')) ?></label>
                                            <?php if ($events !== [] || $courses !== []): ?>
                                                <select name="target_id" required>
                                                    <option value=""><?= esc(lang('Referrals.eventCourseIdNone')) ?></option>
                                                    <?php if ($events !== []): ?>
                                                        <optgroup label="<?= esc(lang('Referrals.typeEvent'), 'attr') ?>">
                                                            <?php foreach ($events as $ev): ?>
                                                                <?php $eid = (string) ($ev['id'] ?? ''); if ($eid === '') { continue; } $et = trim((string) ($ev['title'] ?? '')); ?>
                                                                <option value="<?= esc($eid, 'attr') ?>"><?= esc($et !== '' ? $et : $eid) ?></option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                    <?php endif; ?>
                                                    <?php if ($courses !== []): ?>
                                                        <optgroup label="<?= esc(lang('Referrals.typeCourse'), 'attr') ?>">
                                                            <?php foreach ($courses as $co): ?>
                                                                <?php $coid = (string) ($co['id'] ?? ''); if ($coid === '') { continue; } $ct = trim((string) ($co['title'] ?? '')); ?>
                                                                <option value="<?= esc($coid, 'attr') ?>"><?= esc($ct !== '' ? $ct : $coid) ?></option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                    <?php endif; ?>
                                                </select>
                                            <?php else: ?>
                                                <input name="target_id" maxlength="64" placeholder="<?= esc(lang('Referrals.eventCourseIdPh'), 'attr') ?>" required>
                                            <?php endif; ?>
                                            <button class="btn btn-sm" type="submit"><?= esc(lang('Referrals.register')) ?></button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2><?= esc(lang('Referrals.addContact')) ?></h2>
                <form method="post" action="/me/contacts">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                    <label for="full_name"><?= esc(lang('Referrals.fullNameLbl')) ?></label>
                    <input id="full_name" name="full_name" required maxlength="150" placeholder="<?= esc(lang('Referrals.fullNamePh'), 'attr') ?>">
                    <div class="row2">
                        <div>
                            <label for="phone"><?= esc(lang('Referrals.phoneLbl')) ?></label>
                            <input id="phone" name="phone" placeholder="<?= esc(lang('Referrals.phonePh'), 'attr') ?>">
                        </div>
                        <div>
                            <label for="email"><?= esc(lang('Referrals.emailLbl')) ?></label>
                            <input id="email" name="email" type="email" placeholder="<?= esc(lang('Referrals.emailPh'), 'attr') ?>">
                        </div>
                    </div>
                    <div class="row2">
                        <div>
                            <label for="journey_stage"><?= esc(lang('Referrals.stageLbl')) ?></label>
                            <select id="journey_stage" name="journey_stage">
                                <?php foreach ($stages as $s): ?>
                                    <option value="<?= esc($s) ?>"><?= esc($labelize($s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label for="invite_context_type"><?= esc(lang('Referrals.invitedToLbl')) ?></label>
                    <select id="invite_context_type" name="invite_context_type">
                        <option value=""><?= esc(lang('Referrals.inviteNone')) ?></option>
                        <option value="cause"><?= esc(lang('Referrals.inviteCause')) ?></option>
                        <option value="course"><?= esc(lang('Referrals.inviteCourse')) ?></option>
                        <option value="event"><?= esc(lang('Referrals.inviteEvent')) ?></option>
                        <option value="group"><?= esc(lang('Referrals.inviteGroup')) ?></option>
                    </select>

                    <?php if ($decisionInputs !== []): ?>
                    <div class="consent">
                        <div style="font-size:.8rem;color:#e2e8f0;font-weight:700;"><?= esc(lang('Referrals.decisionSection')) ?></div>
                        <label for="decision_type"><?= esc(lang('Referrals.decisionTypeLbl')) ?></label>
                        <select id="decision_type" name="decision_type">
                            <option value=""><?= esc(lang('Referrals.decisionNone')) ?></option>
                            <?php foreach ($decisionInputs as $dt): ?>
                                <option value="<?= esc($dt) ?>"><?= esc($labelize($dt)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="row2" style="margin-top:8px;">
                            <div>
                                <label for="decision_date"><?= esc(lang('Referrals.decisionDateLbl')) ?></label>
                                <input type="date" id="decision_date" name="decision_date" max="<?= esc(date('Y-m-d')) ?>">
                            </div>
                            <div>
                                <label for="decision_note"><?= esc(lang('Referrals.decisionNoteLbl')) ?></label>
                                <input id="decision_note" name="decision_note" maxlength="255" placeholder="<?= esc(lang('Referrals.decisionNotePh'), 'attr') ?>">
                            </div>
                        </div>
                        <div class="note"><?= esc(lang('Referrals.decisionHint')) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="consent">
                        <div style="font-size:.8rem;color:#e2e8f0;font-weight:700;"><?= esc(lang('Referrals.tagConsent')) ?></div>
                        <label><input type="checkbox" name="consent" value="1" required> <?= esc(lang('Referrals.consentStoreLbl')) ?></label>
                        <div class="note"><?= esc(lang('Referrals.consentStoreNote')) ?></div>
                    </div>

                    <div class="consent">
                        <div style="font-size:.8rem;color:#e2e8f0;font-weight:700;"><?= esc(lang('Referrals.tagGps')) ?></div>
                        <div class="row2">
                            <div><label for="latitude"><?= esc(lang('Referrals.latitudeLbl')) ?></label><input id="latitude" name="latitude" placeholder="5.6037"></div>
                            <div><label for="longitude"><?= esc(lang('Referrals.longitudeLbl')) ?></label><input id="longitude" name="longitude" placeholder="-0.1870"></div>
                        </div>
                        <label><input type="checkbox" name="coords_consent_verbal" value="1"> <?= esc(lang('Referrals.consentVerbal')) ?></label>
                        <label><input type="checkbox" name="coords_consent_confirmed" value="1"> <?= esc(lang('Referrals.consentConfirm')) ?></label>
                        <div class="note"><?= esc(lang('Referrals.consentNote')) ?></div>
                    </div>

                    <button class="btn" type="submit"><?= esc(lang('Referrals.addContactBtn')) ?></button>
                </form>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
