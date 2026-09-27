<?php

declare(strict_types=1);

namespace WBS\AccessControl\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\AccessControl\Policy\AbacConditionEvaluator;
use WBS\AccessControl\Policy\Combinators\SegregationOfDutiesCombinator;
use WBS\AccessControl\Services\AbacPolicyService;
use WBS\AccessControl\Services\AccessRequestService;
use WBS\AccessControl\Services\AuthorizationService;
use WBS\AccessControl\Services\BreakGlassService;
use WBS\AccessControl\Services\DelegationService;
use WBS\AccessControl\Services\GrantCascadeService;
use WBS\AccessControl\Services\GrantScopeWriter;
use WBS\AccessControl\Services\RoleAssignmentService;
use WBS\AccessControl\Services\RoleService;
use WBS\AccessControl\Services\RuleEngine;
use WBS\AccessControl\Services\RuleService;
use WBS\Audit\Config\Services as AuditServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Config\Services as SharedServices;

/**
 * AccessControl service bindings (SRS §6.3, MAC+RBAC+ABAC PDP). Auto-discovered.
 */
class Services extends BaseService
{
    /** Module-local shared instance (avoids the global 'rules' key collision). */
    private static ?RuleService $sharedRules = null;

    public static function abacEvaluator(bool $getShared = true): AbacConditionEvaluator
    {
        if ($getShared) {
            return static::getSharedInstance('abacEvaluator');
        }

        return new AbacConditionEvaluator();
    }

    public static function sodCombinator(bool $getShared = true): SegregationOfDutiesCombinator
    {
        if ($getShared) {
            return static::getSharedInstance('sodCombinator');
        }

        return new SegregationOfDutiesCombinator();
    }

    public static function authorization(bool $getShared = true): AuthorizationService
    {
        if ($getShared) {
            return static::getSharedInstance('authorization');
        }

        return new AuthorizationService(
            Database::connect(),
            self::abacEvaluator(false),
            self::sodCombinator(false),
            SharedServices::groupScope(),
            self::ruleEngine(false),
        );
    }

    /**
     * General-purpose RuBAC rule engine. Reusable across facets (access,
     * membership, gamification, ...); the PDP consumes the `access` facet. Reuses
     * the shared GroupScopeResolver and the SAME AbacConditionEvaluator the PDP
     * evaluates ABAC with, so rule conditions and scope stay consistent.
     */
    public static function ruleEngine(bool $getShared = true): RuleEngine
    {
        if ($getShared) {
            return static::getSharedInstance('ruleEngine');
        }

        return new RuleEngine(
            Database::connect(),
            self::abacEvaluator(false),
            SharedServices::groupScope(),
        );
    }

    /**
     * Administrative CRUD for RuBAC rules. Leaders author rules WITHIN THEIR OWN
     * SCOPE (containment-checked via GroupScopeResolver::scopeContains), with
     * write-time condition validation and an append-only revision trail.
     */
    public static function rules(bool $getShared = true): RuleService
    {
        // NOTE: uses a module-local shared cache rather than
        // getSharedInstance('rules'): the framework's shared registry is keyed by
        // a global string, and WBS\Gamification also exposes a rules() service, so
        // a shared 'rules' key collides across modules and can return the wrong
        // RuleService type (this is the bug that surfaced as a TypeError).
        if ($getShared) {
            return static::$sharedRules ??= self::rules(false);
        }

        return new RuleService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::groupScope(),
            AuditServices::auditLogger(),
            self::abacEvaluator(false),
        );
    }

    public static function accessRequests(bool $getShared = true): AccessRequestService
    {
        if ($getShared) {
            return static::getSharedInstance('accessRequests');
        }

        return new AccessRequestService(
            Database::connect(),
            SharedServices::clock(),
            self::authorization(),
            AuditServices::auditLogger(),
            NotificationServices::notifications(),
            self::grantScopeWriter(false),
            self::delegations(false),
        );
    }

    /**
     * Shared parsing + persistence of a grant's group scope (scope_mode +
     * hand-picked multi-group set + include_crosscut). Used by every grant writer
     * so the full scope model is expressed identically everywhere.
     */
    public static function grantScopeWriter(bool $getShared = true): GrantScopeWriter
    {
        if ($getShared) {
            return static::getSharedInstance('grantScopeWriter');
        }

        return new GrantScopeWriter(Database::connect(), SharedServices::clock());
    }

    /**
     * Direct, group-aware role-assignment management (FR-ACL-003). Reuses the
     * shared GroupScopeResolver so delegated-administration coverage matches the
     * PDP's grant-coverage rule.
     */
    public static function roleAssignments(bool $getShared = true): RoleAssignmentService
    {
        if ($getShared) {
            return static::getSharedInstance('roleAssignments');
        }

        return new RoleAssignmentService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::groupScope(),
            AuditServices::auditLogger(),
            self::delegations(false),
        );
    }

    /**
     * Grant-teardown cascade (Theme B consumer, AC3). Invoked by the JobRouter on
     * account teardown events to revoke a gone subject's assignments/delegations
     * and close their break-glass sessions (system authority, audited).
     */
    public static function grantCascade(bool $getShared = true): GrantCascadeService
    {
        if ($getShared) {
            return static::getSharedInstance('grantCascade');
        }

        return new GrantCascadeService(
            Database::connect(),
            SharedServices::clock(),
            AuditServices::auditLogger(),
        );
    }

    /**
     * Delegation of authority (FR-ACL-005). Reuses the shared GroupScopeResolver
     * so "equal/narrower scope" matches the PDP's coverage rule.
     */
    public static function delegations(bool $getShared = true): DelegationService
    {
        if ($getShared) {
            return static::getSharedInstance('delegations');
        }

        return new DelegationService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::groupScope(),
            AuditServices::auditLogger(),
            self::grantScopeWriter(false),
        );
    }

    /**
     * Break-glass emergency access (FR-ACL-006). Enhanced alerting rides the
     * notification service; the durable trail is the hash-chained audit log.
     */
    public static function breakGlass(bool $getShared = true): BreakGlassService
    {
        if ($getShared) {
            return static::getSharedInstance('breakGlass');
        }

        return new BreakGlassService(
            Database::connect(),
            SharedServices::clock(),
            AuditServices::auditLogger(),
            NotificationServices::notifications(),
            self::grantScopeWriter(false),
        );
    }

    /**
     * Administrative CRUD for the RBAC role catalogue and its permission grants
     * (FR-ACL-002/003). Reuses the shared GroupScopeResolver so the org-wide
     * management gate matches the PDP's coverage rule.
     */
    public static function roles(bool $getShared = true): RoleService
    {
        if ($getShared) {
            return static::getSharedInstance('roles');
        }

        return new RoleService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::groupScope(),
            AuditServices::auditLogger(),
        );
    }

    /**
     * Administrative CRUD for ABAC policies. Condition trees are validated at
     * write time through the SAME evaluator the PDP decides with, so a malformed
     * policy is rejected on save rather than silently fail-closing at runtime.
     */
    public static function abacPolicies(bool $getShared = true): AbacPolicyService
    {
        if ($getShared) {
            return static::getSharedInstance('abacPolicies');
        }

        return new AbacPolicyService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::groupScope(),
            AuditServices::auditLogger(),
            self::abacEvaluator(false),
        );
    }
}
