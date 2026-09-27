<?php

declare(strict_types=1);

namespace WBS\Referrals\Controllers;

use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Referral + sponsorship endpoints (SRS FR-MEM-*).
 *
 * The public cloaked-link routes (land/captureProspect) never expose the
 * sponsor id or internal ids; they only resolve to a branded landing target and
 * capture consent-appropriate metadata.
 */
final class ReferralController extends BaseController
{
    public function createLink()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(ReferralServices::referrals()->createLink(
            $orgId,
            (string) ($in['referrer_id'] ?? ''),
            [
                'campaign' => $in['campaign'] ?? null,
                'landing'  => $in['landing'] ?? null,
            ],
        ));
    }

    public function assignSponsor()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(ReferralServices::sponsorships()->assign(
            $orgId,
            (string) ($in['member_id'] ?? ''),
            (string) ($in['sponsor_id'] ?? ''),
            (string) ($in['reason'] ?? ''),
        ));
    }

    public function attribute()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(ReferralServices::referrals()->attributeConversion(
            $orgId,
            (string) ($in['referrer_id'] ?? ''),
            (string) ($in['converted_user_id'] ?? ''),
            (string) ($in['conversion_type'] ?? ''),
            (string) ($in['source_ref'] ?? ''),
            [
                'link_id'     => $in['link_id'] ?? null,
                'campaign'    => $in['campaign'] ?? null,
                // R2: identifiers that scope the prospect state-flip to the ONE
                // prospect who converted (never the referrer's whole list).
                'prospect_id' => $in['prospect_id'] ?? null,
                'email_hash'  => $in['email_hash'] ?? null,
            ],
        ));
    }

    /**
     * Public cloaked-link landing (GET r/{code}).
     *
     * Records a click (analytics side effect; safe on GET — it mutates only the
     * click log, never the caller's state, so it needs no CSRF token) and then:
     *  - API clients get the raw Result (link_id + typed redirect target);
     *  - browsers get a bespoke, no-JS INVITATION page — a branded welcome with
     *    the campaign label (never the sponsor id), a "continue to destination"
     *    link, and a CONSENT-GATED prospect-capture form. `renderForm` mints the
     *    `wbs_csrf` cookie the anonymous visitor's POST will double-submit.
     *
     * An unknown / inactive code shows a friendly "link expired" page (browser)
     * or a 404 Result (API) — the sponsor is never revealed either way.
     */
    public function land(string $code = '')
    {
        $utm = $this->utmParams();

        $result = ReferralServices::referrals()->recordClick($code, [
            'ip'         => $this->request->getIPAddress(),
            'user_agent' => $this->request->getUserAgent()->getAgentString(),
            // Consent lives on the POST capture step, not the click — a bare
            // visit is not consent.
            'consent'    => false,
            // Normalized client signals for a privacy-safe device_hash (raw
            // components are never persisted).
            'device'     => [
                'screen'   => (string) ($this->field('screen_resolution', '')),
                'timezone' => (string) ($this->field('timezone', '')),
                'ua_class' => $this->request->getUserAgent()->getPlatform(),
            ],
            // UTM passthrough for the typed redirect (G4).
            'utm'        => $utm,
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        // Unknown / inactive link -> friendly expired page (no sponsor leak).
        if (! $result->ok) {
            return $this->renderForm('WBS\\Referrals\\Views\\invite_expired', [
                'code' => $code,
            ]);
        }

        $data = $result->data ?? [];

        // Which optional decision inputs the landing may offer — resolved by
        // the referral service from the invite link (capture_inputs config),
        // default none.
        $decisionInputs = [];
        $link = ReferralServices::referrals()->resolve($code);
        if ($link !== null) {
            $decisionInputs = ReferralServices::referrals()->captureInputsForLink($link);
        }

        return $this->renderForm('WBS\\Referrals\\Views\\invite', [
            'code'     => $code,
            'campaign' => $data['campaign'] ?? null,
            'linkType' => (string) ($data['link_type'] ?? 'member'),
            'redirect' => (string) ($data['redirect'] ?? base_url()),
            'decisionInputs' => $decisionInputs,
        ]);
    }

    /** Click analytics for one cloaked link (PII-free aggregates). */
    public function linkAnalytics(string $code = '')
    {
        $period = (string) ($this->field('period', '30 days'));

        return $this->respondPage(
            ReferralServices::referrals()->getLinkAnalytics($code, $period),
            'referral_link_analytics',
            static function (array $d): array {
                if (array_is_list($d)) {
                    return ['rows' => $d];
                }
                $rows = [];
                foreach ($d as $label => $value) {
                    if (is_scalar($value) || $value === null) {
                        $rows[] = ['label' => (string) $label, 'value' => $value];
                    }
                }

                return ['rows' => $rows];
            },
        );
    }

    /** Aggregate click analytics across all of a referrer's links. */
    public function referrerAnalytics(string $referrerId = '')
    {
        $period = (string) ($this->field('period', '30 days'));

        return $this->respondPage(
            ReferralServices::referrals()->getReferrerAnalytics($referrerId, $period),
            'referral_referrer_analytics',
            static function (array $d): array {
                if (array_is_list($d)) {
                    return ['rows' => $d];
                }
                $rows = [];
                foreach ($d as $label => $value) {
                    if (is_scalar($value) || $value === null) {
                        $rows[] = ['label' => (string) $label, 'value' => $value];
                    }
                }

                return ['rows' => $rows];
            },
        );
    }

    /**
     * Upward sponsor chain for a member (member IDs only — no names/emails).
     */
    public function chain(string $memberId = '')
    {
        $chain = ReferralServices::sponsorships()->upline($memberId);

        $out = [];
        foreach ($chain as $i => $sponsorId) {
            $out[] = ['level' => $i + 1, 'member_id' => $sponsorId];
        }

        // Browsers get the bespoke chain view (no raw JSON); API clients get JSON.
        return $this->respondWith(
            Result::ok(['member_id' => $memberId, 'chain' => $out]),
            'WBS\Referrals\Views\chain',
            null,
            ['memberId' => $memberId, 'chain' => $out],
        );
    }

    /** Manual review: flag a click as suspicious. */
    public function flagClick(string $clickId = '')
    {
        $reason = (string) ($this->field('reason', 'manual_review'));

        return $this->respondWith(
            ReferralServices::referrals()->markSuspicious($clickId, $reason),
        );
    }

    /** Manual review: clear a click's suspicious flag. */
    public function clearClick(string $clickId = '')
    {
        return $this->respondWith(
            ReferralServices::referrals()->clearSuspicious($clickId),
        );
    }

    /**
     * Capture a consent-gated prospect from a cloaked link (POST r/{code}/prospect).
     *
     * Guests may submit (no `auth`), so the route is webcsrf-guarded and the
     * landing page (renderForm) mints the double-submit token. On success a
     * browser is PRG-redirected onward to the link's branded destination; on a
     * missing-consent / not-found failure it returns to the invite page with a
     * localized flash. API clients keep the raw Result.
     */
    public function captureProspect(string $code = '')
    {
        $in = $this->input();

        $result = ReferralServices::referrals()->captureProspect($code, [
            'email'        => $in['email'] ?? null,
            'display_name' => $in['display_name'] ?? null,
            'phone'        => $in['phone'] ?? null, // graded landing subset: optional phone (field-sync decision)
            'consent'      => (bool) ($in['consent'] ?? false),
            // Optional integration-decision input (group `capture_inputs`);
            // visitor-filled -> landing source, born pending (onboarding decision).
            'decision_type' => trim((string) ($in['decision_type'] ?? '')),
            'decision_date' => trim((string) ($in['decision_date'] ?? '')),
            'decision_note' => $in['decision_note'] ?? null,
            'utm'          => $this->utmParams(),
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if (! $result->ok) {
            return redirect()->to('/r/' . rawurlencode($code))
                ->with('error', $this->errText((string) $result->message));
        }

        // Send the just-captured prospect onward to the branded destination.
        $redirect = (string) (($result->data['redirect'] ?? '') ?: base_url());

        return redirect()->to($redirect)
            ->with('success', lang('Referrals.invite.capturedFlash'));
    }

    /**
     * Extract the whitelisted UTM passthrough params from the current request
     * (query or post). Shared by land() and captureProspect().
     *
     * @return array<string,string>
     */
    private function utmParams(): array
    {
        return [
            'utm_source'   => (string) ($this->field('utm_source', '')),
            'utm_medium'   => (string) ($this->field('utm_medium', '')),
            'utm_campaign' => (string) ($this->field('utm_campaign', '')),
            'utm_term'     => (string) ($this->field('utm_term', '')),
            'utm_content'  => (string) ($this->field('utm_content', '')),
        ];
    }
}
