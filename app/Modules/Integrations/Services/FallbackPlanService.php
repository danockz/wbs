<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use WBS\Integrations\Providers\FallbackMatrix;
use WBS\Shared\Support\Result;

/**
 * Read-side that turns the code-owned {@see FallbackMatrix} + an adapter's
 * DECLARED capabilities into a documented per-feature coverage plan
 * (SRS FR-INT-012).
 *
 * For every broadcast/meeting adapter it answers, feature by feature: does the
 * provider serve this via its approved API (primary), or does the platform use
 * the documented fallback (hosted link, manual import, external VOD, or
 * platform-native chat/poll)? This is what the UI renders and what auditors read
 * to confirm every required scenario is configurable without faking provider
 * capabilities or exposing secrets.
 */
final class FallbackPlanService
{
    /** Catalogue categories that have a fallback matrix. */
    private const COVERED = ['stream', 'meeting'];

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly FallbackMatrix $matrix,
    ) {
    }

    /** Documented coverage plan for a single adapter (latest active version). */
    public function forAdapter(string $code, ?int $version = null): Result
    {
        $res = $this->catalog->adapter($code, $version);
        if (! $res->ok) {
            return $res;
        }
        $adapter  = $res->data;
        $category = (string) $adapter['category'];
        if (! in_array($category, self::COVERED, true)) {
            return Result::fail(
                'NO_FALLBACK_MATRIX',
                'integration.no_fallback_matrix',
                422,
                [],
                ['category' => $category, 'covered' => self::COVERED],
            );
        }

        $caps = $this->catalog->capabilities($code, $version);
        $plan = $this->matrix->plan($category, $caps);

        return Result::ok([
            'adapter'      => $code,
            'display_name' => $adapter['display_name'] ?? $code,
            'category'     => $category,
            'capabilities' => $caps,
            'features'     => $plan,
            'summary'      => $this->summarize($plan),
        ]);
    }

    /**
     * The full matrix across every active broadcast/meeting adapter — one
     * document describing how every scenario is served or degraded.
     */
    public function matrix(): Result
    {
        $out = [];
        foreach (self::COVERED as $category) {
            foreach ($this->catalog->activeAdapters($category) as $adapter) {
                $code = (string) $adapter['code'];
                $caps = $this->catalog->capabilities($code);
                $plan = $this->matrix->plan($category, $caps);
                $out[] = [
                    'adapter'      => $code,
                    'display_name' => $adapter['display_name'] ?? $code,
                    'category'     => $category,
                    'features'     => $plan,
                    'summary'      => $this->summarize($plan),
                ];
            }
        }

        return Result::ok(['matrix' => $out, 'count' => count($out)]);
    }

    /**
     * @param list<array<string,mixed>> $plan
     *
     * @return array<string,int>
     */
    private function summarize(array $plan): array
    {
        $s = ['primary' => 0, 'fallback' => 0, 'platform_native' => 0];
        foreach ($plan as $f) {
            $mode = (string) $f['mode'];
            $s[$mode] = ($s[$mode] ?? 0) + 1;
        }

        return $s;
    }
}
