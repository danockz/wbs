<?php

declare(strict_types=1);

namespace WBS\Events\Support;

/**
 * A committee member's SPECIFIC RESPONSIBILITY — the fixed vocabulary behind
 * `event_committee_members.responsibility`.
 *
 * Two rules keep this honest:
 *
 *  1. A responsibility is a LABEL plus, optionally, the ONE existing permission
 *     bit it carries. There are deliberately no new permission bits: appointing a
 *     finance lead delegates `event.expense.submit`, a registration lead
 *     `event.tickets.manage`, and so on. The vocabulary can therefore never grant
 *     authority the ACL catalogue does not already define, and the platform's
 *     permission budget stays frozen.
 *  2. Approval bits are never delegated by a responsibility. `event.expense.approve`
 *     and `event.schedule.approve` stay with the leader (or whoever the leader
 *     separately delegates them to), so a committee that spends or reschedules
 *     still meets a checker on the other side — segregation of duties survives the
 *     committee.
 *
 * `general` carries no bit: such a member participates in the plan (sees and works
 * the tasks) but acts on other surfaces only through a delegation the chair or
 * leader grants explicitly.
 */
final class CommitteeResponsibility
{
    public const CHAIR          = 'chair';
    public const PROGRAMME      = 'programme';
    public const LOGISTICS      = 'logistics';
    public const FINANCE        = 'finance';
    public const COMMUNICATIONS = 'communications';
    public const REGISTRATION   = 'registration';
    public const MEDIA          = 'media';
    public const CHECKIN        = 'checkin';
    public const CERTIFICATES   = 'certificates';
    public const FEEDBACK       = 'feedback';
    public const GENERAL        = 'general';

    /** @var list<string> */
    public const ALL = [
        self::CHAIR,
        self::PROGRAMME,
        self::LOGISTICS,
        self::FINANCE,
        self::COMMUNICATIONS,
        self::REGISTRATION,
        self::MEDIA,
        self::CHECKIN,
        self::CERTIFICATES,
        self::FEEDBACK,
        self::GENERAL,
    ];

    /**
     * responsibility => the existing capability delegated on appointment.
     * NULL means "participation only, no delegation".
     *
     * @var array<string,string|null>
     */
    private const PERMISSIONS = [
        // The chair runs the plan; logistics is the plan's own surface. Anything
        // stronger (spending, rescheduling, publishing) is delegated separately.
        self::CHAIR          => 'event.logistics.manage',
        self::PROGRAMME      => 'event.logistics.manage',
        self::LOGISTICS      => 'event.logistics.manage',
        // Submit only — approval stays with the leader (SoD).
        self::FINANCE        => 'event.expense.submit',
        self::COMMUNICATIONS => 'notification.send',
        self::REGISTRATION   => 'event.tickets.manage',
        self::MEDIA          => 'event.media.manage',
        self::CHECKIN        => 'attendance.check_in',
        self::CERTIFICATES   => 'event.certificate.manage',
        self::FEEDBACK       => 'event.feedback.manage',
        self::GENERAL        => null,
    ];

    private function __construct()
    {
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::ALL, true);
    }

    /** Coerce free input to a known responsibility; unknown ⇒ GENERAL. */
    public static function normalize(?string $value): string
    {
        $v = strtolower(trim((string) $value));

        return self::isValid($v) ? $v : self::GENERAL;
    }

    /** The existing permission bit this responsibility carries, if any. */
    public static function permissionFor(string $responsibility): ?string
    {
        return self::PERMISSIONS[self::normalize($responsibility)] ?? null;
    }

    /** True when appointment should create a time-bounded delegation. */
    public static function delegates(string $responsibility): bool
    {
        return self::permissionFor($responsibility) !== null;
    }

    /** i18n key for the localized label (all six locales carry it). */
    public static function labelKey(string $responsibility): string
    {
        return 'Events.committee.resp' . ucfirst(self::normalize($responsibility));
    }

    /**
     * Responsibilities that may hold the chair. Every responsibility can be the
     * chair's own lane — the chair is a MEMBER with is_chair set, not a separate
     * kind of row — but the vocabulary keeps `chair` for a chair whose lane is
     * chairing itself.
     *
     * @return list<string>
     */
    public static function chairEligible(): array
    {
        return self::ALL;
    }
}
