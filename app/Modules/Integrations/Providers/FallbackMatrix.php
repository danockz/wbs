<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Documented per-feature fallback matrix (SRS FR-INT-012).
 *
 * For every broadcast/meeting feature the platform needs, this registry names
 * the PRIMARY path (the provider's approved API capability) and the DOCUMENTED
 * FALLBACK to use when the provider does not offer that feature — or when its
 * circuit is open / quota exhausted. Fallbacks never expose provider secrets and
 * never fabricate data: they degrade to a hosted link, a manual import/review, an
 * external VOD link, or a platform-native feature.
 *
 * This is code-owned (not a DB table) so the fallback behaviour is reviewed and
 * versioned with the adapters it describes. The effective plan for a concrete
 * connection is derived from the adapter's DECLARED capabilities (FR-INT-011),
 * so we never claim a primary path an adapter did not declare.
 */
final class FallbackMatrix
{
    /**
     * category => feature => [capability, primary, fallback, fallback_note].
     *
     *  - capability: the canonical op an adapter must declare to use the primary
     *    path. null means the feature is always platform-native.
     *  - primary:  human label for the provider-backed path.
     *  - fallback: machine code the caller switches on when primary is
     *    unavailable (hosted_link | manual_import | external_vod |
     *    platform_native | unavailable).
     *
     * @var array<string, array<string, array{capability:?string, primary:string, fallback:string, note:string}>>
     */
    private const MATRIX = [
        'stream' => [
            'broadcast' => [
                'capability' => 'createMeetingLink',
                'primary'    => 'Provider API creates the broadcast/destination.',
                'fallback'   => 'hosted_link',
                'note'       => 'Store an authorized secure RTMP/hosted watch link configured by the organizer; no provider API call.',
            ],
            'metrics' => [
                'capability' => 'getStreamMetrics',
                'primary'    => 'Poll provider-reported viewer/engagement metrics (tagged exact/estimated).',
                'fallback'   => 'platform_native',
                'note'       => 'Use platform-native viewer/engagement counts and mark provider metrics unavailable — never fabricated.',
            ],
            'chat' => [
                'capability' => null,
                'primary'    => 'Platform-native live chat.',
                'fallback'   => 'platform_native',
                'note'       => 'Chat is always platform-native (audited, moderated); provider chat is not ingested.',
            ],
            'poll' => [
                'capability' => null,
                'primary'    => 'Platform-native live polls.',
                'fallback'   => 'platform_native',
                'note'       => 'Polls are always platform-native.',
            ],
            'vod' => [
                'capability' => 'getRecording',
                'primary'    => 'Link the provider-retained recording as an archived asset.',
                'fallback'   => 'external_vod',
                'note'       => 'Store an organizer-supplied external VOD URL (FR-STR-012); no recording is fabricated.',
            ],
        ],
        'meeting' => [
            'create' => [
                'capability' => 'createMeetingLink',
                'primary'    => 'Provider API creates the meeting/webinar.',
                'fallback'   => 'hosted_link',
                'note'       => 'Store an organizer-supplied authorized join link; per-user join tokens still issued by the platform.',
            ],
            'attendance' => [
                'capability' => 'getParticipants',
                'primary'    => 'Pull provider presence as CANDIDATE evidence for the event verification policy.',
                'fallback'   => 'manual_import',
                'note'       => 'Manual attendance import/review — presence is candidate evidence only, never auto-marked attendance.',
            ],
            'recording' => [
                'capability' => 'getRecording',
                'primary'    => 'Link the provider recording as an archived asset.',
                'fallback'   => 'external_vod',
                'note'       => 'Store an organizer-supplied external VOD URL.',
            ],
            'chat' => [
                'capability' => null,
                'primary'    => 'Platform-native chat where the meeting is embedded.',
                'fallback'   => 'platform_native',
                'note'       => 'Platform-native chat; provider chat is not ingested.',
            ],
            'poll' => [
                'capability' => null,
                'primary'    => 'Platform-native polls.',
                'fallback'   => 'platform_native',
                'note'       => 'Platform-native polls.',
            ],
        ],
    ];

    /** @return list<string> categories covered by the matrix. */
    public function categories(): array
    {
        return array_keys(self::MATRIX);
    }

    /**
     * The features defined for a category.
     *
     * @return list<string>
     */
    public function features(string $category): array
    {
        return array_keys(self::MATRIX[$category] ?? []);
    }

    /**
     * Resolve the effective plan for one feature given the adapter's DECLARED
     * capabilities. Returns which path is active (primary vs fallback) and why.
     *
     * @param list<string> $declaredCapabilities
     *
     * @return array{
     *   category:string, feature:string, capability:?string,
     *   supported:bool, mode:string, description:string,
     *   fallback:string, fallback_note:string
     * }
     */
    public function resolve(string $category, string $feature, array $declaredCapabilities): array
    {
        $entry = self::MATRIX[$category][$feature] ?? null;
        if ($entry === null) {
            return [
                'category'      => $category,
                'feature'       => $feature,
                'capability'    => null,
                'supported'     => false,
                'mode'          => 'unavailable',
                'description'   => 'No such feature in the fallback matrix.',
                'fallback'      => 'unavailable',
                'fallback_note' => 'Feature is not part of the platform capability set.',
            ];
        }

        $cap = $entry['capability'];
        // A null capability = always platform-native (primary IS the fallback).
        $primaryAvailable = $cap === null || in_array($cap, $declaredCapabilities, true);
        $mode = $cap === null
            ? 'platform_native'
            : ($primaryAvailable ? 'primary' : 'fallback');

        return [
            'category'      => $category,
            'feature'       => $feature,
            'capability'    => $cap,
            'supported'     => true,
            'mode'          => $mode,
            'description'   => $mode === 'fallback' ? $entry['note'] : $entry['primary'],
            'fallback'      => $entry['fallback'],
            'fallback_note' => $entry['note'],
        ];
    }

    /**
     * The full plan for an adapter category given its declared capabilities:
     * every feature with its active mode. Used to generate honest UI and to
     * document exactly how each scenario is served or degraded.
     *
     * @param list<string> $declaredCapabilities
     *
     * @return list<array<string,mixed>>
     */
    public function plan(string $category, array $declaredCapabilities): array
    {
        $out = [];
        foreach ($this->features($category) as $feature) {
            $out[] = $this->resolve($category, $feature, $declaredCapabilities);
        }

        return $out;
    }

    /**
     * When a primary path fails at RUNTIME (circuit open, quota exhausted, or a
     * provider error), this returns the documented fallback directive for the
     * feature regardless of declared capability — so callers have one place to
     * ask "what do I do instead?".
     *
     * @return array{fallback:string, note:string}
     */
    public function fallbackFor(string $category, string $feature): array
    {
        $entry = self::MATRIX[$category][$feature] ?? null;
        if ($entry === null) {
            return ['fallback' => 'unavailable', 'note' => 'No documented fallback for this feature.'];
        }

        return ['fallback' => $entry['fallback'], 'note' => $entry['note']];
    }
}
