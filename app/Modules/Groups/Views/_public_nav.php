<?php
/**
 * Shared auth-aware nav bar for PUBLIC group pages (directory, landing, join).
 *
 * Chrome CSS lives in assets/css/app.css (hashed via Config\\Assets). Rendered
 * on pages with NO `auth` filter, so login state is
 * resolved by GroupPublicController::viewerContext() and passed in as $viewer.
 *
 * @var array<string,mixed>|null $viewer  { id, display_name } when signed in, else null
 * @var string|null              $csrf    CSRF token for the logout form (signed-in only)
 */
$viewer = $viewer ?? null;
$csrf   = $csrf ?? null;
?>
<div class="wbs-pubnav">
    <a class="wbs-pubnav__brand" href="/g"><?= esc(lang('Groups.nav.groups')) ?></a>
    <div class="wbs-pubnav__spacer"></div>
    <?php if (! empty($viewer)): ?>
        <span class="wbs-pubnav__who"><?= str_replace('{0}', '<strong>' . esc((string) $viewer['display_name']) . '</strong>', esc(lang('Groups.nav.signedInAs'))) ?></span>
        <a class="wbs-pubnav__btn" href="/me/dashboard"><?= esc(lang('Groups.nav.myDashboard')) ?></a>
        <form class="wbs-pubnav__logout" method="post" action="/logout">
            <input type="hidden" name="_csrf" value="<?= esc((string) ($csrf ?? '')) ?>">
            <button class="wbs-pubnav__btn" type="submit"><?= esc(lang('Groups.nav.logOut')) ?></button>
        </form>
    <?php else: ?>
        <a class="wbs-pubnav__btn" href="/login?return=/me/dashboard"><?= esc(lang('Groups.nav.memberLogin')) ?></a>
    <?php endif; ?>
</div>

