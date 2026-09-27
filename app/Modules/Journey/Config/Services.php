<?php

declare(strict_types=1);

namespace WBS\Journey\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Admin\Config\Services as AdminServices;
use WBS\Contributions\Config\Services as ContributionsServices;
use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Journey\Services\EffectiveConfigAdapter;
use WBS\Journey\Services\EffectiveConfigWriteAdapter;
use WBS\Journey\Services\InvolvementService;
use WBS\Journey\Services\InvolvementSourceAdapter;
use WBS\Journey\Services\JourneyAttributionService;
use WBS\Journey\Services\JourneyRecommendationService;
use WBS\Journey\Services\JourneyProposalAgingService;
use WBS\Journey\Services\JourneyService;
use WBS\Journey\Services\JourneySignalService;
use WBS\Journey\Services\ProposalSupersedeListener;
use WBS\Journey\Services\SettingsProposalConfigAdapter;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Referrals\Config\Services as ReferralsServices;
use WBS\Referrals\Services\IntegrationGateAdapter;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Membership Journey service bindings (assessment Options B, C & D).
 * Auto-discovered.
 */
class Services extends BaseService
{
    public static function journey(bool $getShared = true): JourneyService
    {
        if ($getShared) {
            return static::getSharedInstance('journey');
        }

        $involvement = self::involvement();
        $db          = Database::connect();

        // A bare, listener-less JourneyService the supersede listener uses purely
        // for its read-only ladder comparison (compareStages), so wiring the
        // listener into the primary instance's listener array can't recurse.
        $readModel = new JourneyService($db, SharedServices::clock(), SharedServices::groupScope());

        return new JourneyService(
            $db,
            SharedServices::clock(),
            SharedServices::groupScope(),
            // Listeners notified after a committed transition:
            //  - Option D: credit the discipler through the existing gamification
            //    stack on every forward transition (config-gated, default off);
            //  - keep the involvement snapshot fresh for the moved member;
            //  - J6: supersede any pending proposal the move made stale/regress.
            [self::attribution(), $involvement, new ProposalSupersedeListener($db, $readModel, SharedServices::clock())],
            // Involvement-based triage read side (config-gated, default off): when
            // enabled for a context the pipeline/roster classify by involvement.
            $involvement,
            // Integration gate (FR-REF-3b): a stage move into In Foundation /
            // Established is refused until the member is integrated. Config-gated
            // (default off) inside the adapter.
            // Non-shared: CI4 getSharedInstance('integration') goes through
            // AppServices::__callStatic and returns null when this factory is
            // not yet in the discovery cache (accounts → journeySignals →
            // journey on GET /me). Adapter also lazy-resolves if given null.
            new IntegrationGateAdapter(ReferralsServices::integration(false)),
        );
    }

    /**
     * Involvement-based triage engine (see docs/TODO_INVOLVEMENT_BASED_TRIAGE.md).
     * Wires the hierarchical-config adapter (over EffectiveConfigResolver) and the
     * cross-module source adapter (Referrals/Contributions/Gamification + activity
     * tables). The heavy cross-module reads run ONLY on the snapshot write path.
     */
    public static function involvement(bool $getShared = true): InvolvementService
    {
        if ($getShared) {
            return static::getSharedInstance('involvement');
        }

        $db = Database::connect();

        return new InvolvementService(
            $db,
            SharedServices::clock(),
            SharedServices::groupScope(),
            new EffectiveConfigAdapter(AdminServices::effectiveConfig()),
            new InvolvementSourceAdapter(
                $db,
                ReferralsServices::sponsorships(),
                ContributionsServices::metrics(),
                GamificationServices::pointsEngine(),
            ),
            new EffectiveConfigWriteAdapter(AdminServices::effectiveConfig()),
        );
    }

    /** Disciple-making attribution listener (Option D). */
    public static function attribution(bool $getShared = true): JourneyAttributionService
    {
        if ($getShared) {
            return static::getSharedInstance('attribution');
        }

        return new JourneyAttributionService(
            GamificationServices::config(),
            GamificationServices::pointsEngine(),
        );
    }

    /**
     * Rule-driven journey transitions (Option C): bridges signals to the shared
     * RuBAC RuleEngine (facet = membership) and applies/proposes transitions.
     */
    public static function journeySignals(bool $getShared = true): JourneySignalService
    {
        if ($getShared) {
            return static::getSharedInstance('journeySignals');
        }

        return new JourneySignalService(
            Database::connect(),
            SharedServices::clock(),
            self::journey(false),
            AccessControlServices::ruleEngine(),
        );
    }

    /**
     * J6 — pending journey-proposal aging (remind → escalate → optional timeout).
     * Reads per-org SLA thresholds from the org-wide SettingsService and notifies
     * the reviewing leaders / escalation role. The supersede-on-move half runs
     * inline via ProposalSupersedeListener on every transition.
     */
    public static function proposalAging(bool $getShared = true): JourneyProposalAgingService
    {
        if ($getShared) {
            return static::getSharedInstance('proposalAging');
        }

        return new JourneyProposalAgingService(
            Database::connect(),
            SharedServices::clock(),
            new SettingsProposalConfigAdapter(AdminServices::settings()),
            NotificationServices::notifications(),
        );
    }

    /**
     * Recommended next activities (Option D follow-on): reads stage-linked
     * activities/categories/follow-up types for where a member is on the ladder.
     */
    public static function recommendations(bool $getShared = true): JourneyRecommendationService
    {
        if ($getShared) {
            return static::getSharedInstance('recommendations');
        }

        return new JourneyRecommendationService(
            Database::connect(),
            self::journey(),
            SharedServices::groupScope(),
        );
    }
}
