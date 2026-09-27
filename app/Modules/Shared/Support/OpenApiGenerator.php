<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

use Config\Services;

/**
 * Builds an OpenAPI 3.1 spec from the canonical RouteCollection (SRS FR-ARC-001/002).
 *
 * Route-derived by design: there are NO per-action `#[OA\...]` annotations to
 * maintain, so the spec can never drift from the real routes. Security
 * requirements, required permissions, and rate-limit policies are read straight
 * from each route's filters; every operation shares the platform's uniform
 * response envelope (Success `data`/`meta`, or RFC 9457 Problem on error).
 *
 * The bundled tools/gen_openapi.py produces the identical artifact for
 * environments without the PHP toolchain.
 */
final class OpenApiGenerator
{
    private const TAG_DESCRIPTIONS = [
        'Identity'      => 'Registration, login, MFA, social sign-in, API tokens.',
        'Groups'        => 'Organizational group hierarchy.',
        'Geo'           => 'Geo resolution, reverse-geocoding, and managed venues.',
        'Referrals'     => 'Cloaked referral links, clicks, attribution, sponsorship.',
        'Events'        => 'Event lifecycle, registration, and check-in.',
        'Contributions' => 'VBCS causes, contributions, refunds, metrics, partnership, commitments.',
        'Courses'       => 'Learning courses, lessons, enrolment.',
        'Notifications' => 'Preference-gated notifications and campaigns.',
        'Integrations'  => 'UPAF provider catalog, connections, credentials, OAuth.',
        'Community'     => 'Feeds, posts, comments, reactions, moderation.',
        'Streaming'     => 'Stream orchestration, overlays, and live engagement.',
        'Meetings'      => 'Provider meeting/webinar integrations.',
        'Gamification'  => 'Points, leaderboards, achievements, streaks, ranks.',
        'Reporting'     => 'Dashboards and exports.',
        'Admin'         => 'Platform settings, feature flags, group config.',
        'System'        => 'Health and service metadata.',
    ];

    /** @return array<string,mixed> */
    public function generate(): array
    {
        $collection = Services::routes();
        $collection->loadRoutes();

        $paths = [];
        $tags  = [];
        $perms = [];

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
            foreach ($collection->getRoutes($verb) as $from => $to) {
                if (! is_string($to)) {
                    continue; // closures/arrays are not documentable handlers
                }
                $handler = $to;
                [$oapath, $params] = $this->toOpenApiPath('/' . ltrim($from, '/'));
                $tag = $this->tagFromHandler($handler);
                $tags[$tag] = true;

                $filters = $this->filtersFor($collection, $from, $verb);
                $op      = $this->operation($verb, $handler, $params, $filters, $oapath);
                foreach ($op['x-permissions'] ?? [] as $p) {
                    $perms[$p] = true;
                }
                unset($op['x-permissions']);

                $paths[$oapath][$verb] = $op;
            }
        }

        ksort($paths);
        $tagList = [];
        foreach (array_keys($tags) as $t) {
            $tagList[] = ['name' => $t, 'description' => self::TAG_DESCRIPTIONS[$t] ?? ''];
        }
        usort($tagList, static fn ($a, $b): int => strcmp($a['name'], $b['name']));
        $permList = array_keys($perms);
        sort($permList);

        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'Win–Build–Send Platform API',
                'version'     => '1.0.0',
                'description' => "Canonical unified web/API for the WBS Membership, Capacity-Building & "
                    . "Community Platform.\n\nThere is ONE canonical URL per resource (no `/api` tree); "
                    . "JSON vs. HTML is chosen by representation negotiation. Every JSON response uses a "
                    . "uniform envelope: successes carry `data` (+ optional `meta`); errors follow RFC 9457 "
                    . "Problem Details.\n\nThis spec is generated from the route table — it cannot drift "
                    . "from the real routes.",
            ],
            'servers'    => [['url' => '/', 'description' => 'Same-origin canonical endpoints']],
            'tags'       => $tagList,
            'paths'      => $paths,
            'components' => [
                'securitySchemes' => [
                    'BearerToken' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'opaque',
                        'description'  => 'Session reference or API access token (prefix `wbsat_`) as a Bearer credential.',
                    ],
                ],
                'schemas' => [
                    'Success' => [
                        'type'       => 'object',
                        'properties' => [
                            'data' => ['description' => 'Result payload (object, array, or scalar wrapper).'],
                            'meta' => ['type' => 'object', 'additionalProperties' => true,
                                'description' => 'Optional metadata (pagination, dedupe flags, etc.).'],
                        ],
                        'required' => ['data'],
                    ],
                    'Problem' => [
                        'type'        => 'object',
                        'description' => 'RFC 9457 problem details.',
                        'properties'  => [
                            'type'   => ['type' => 'string', 'default' => 'about:blank'],
                            'title'  => ['type' => 'string', 'description' => 'Stable error code.'],
                            'status' => ['type' => 'integer'],
                            'detail' => ['type' => 'string', 'description' => 'Human-readable / message key.'],
                            'errors' => ['type' => 'object', 'additionalProperties' => true],
                            'meta'   => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'required' => ['title', 'status'],
                    ],
                ],
            ],
            'x-permissions' => $permList,
        ];
    }

    /** @return list<string> */
    private function filtersFor(object $collection, string $from, string $verb): array
    {
        $out = [];
        if (method_exists($collection, 'getFiltersForRoute')) {
            // CI 4.4+ helper (if present).
            $out = (array) $collection->getFiltersForRoute($from, $verb);
        }

        return array_values(array_filter(array_map('strval', $out)));
    }

    /**
     * @param list<string> $params
     * @param list<string> $filters
     * @return array<string,mixed>
     */
    private function operation(string $verb, string $handler, array $params, array $filters, string $oapath): array
    {
        $handler = ltrim($handler, '\\');
        [$ctrl, $method] = array_pad(explode('::', $handler, 2), 2, '');
        $method = explode('/', $method)[0];
        $tag    = $this->tagFromHandler($handler);

        $secured = false;
        $authz   = [];
        $rl      = [];
        foreach ($filters as $f) {
            if ($f === 'auth') {
                $secured = true;
            } elseif (str_starts_with($f, 'authorize:')) {
                $secured = true;
                $authz[] = substr($f, 10);
            } elseif (str_starts_with($f, 'ratelimit:')) {
                $rl[] = substr($f, 10);
            }
        }

        $desc = "Handler `{$ctrl}::{$method}`.";
        $bits = [];
        if ($authz !== []) {
            $bits[] = 'Requires permission(s): ' . implode(', ', array_map(static fn ($p) => "`{$p}`", $authz)) . '.';
        } elseif ($secured) {
            $bits[] = 'Requires an authenticated session or API token.';
        }
        if ($rl !== []) {
            $bits[] = 'Rate-limited by policy: ' . implode(', ', array_map(static fn ($p) => "`{$p}`", $rl)) . '.';
        }
        if ($bits !== []) {
            $desc .= "\n\n" . implode(' ', $bits);
        }

        $op = [
            'tags'        => [$tag],
            'operationId' => $method . '_' . $verb . '_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', $oapath), '_'),
            'summary'     => $this->humanize($method),
            'description' => $desc,
            'responses'   => [],
        ];

        if ($params !== []) {
            $op['parameters'] = array_map(static fn ($p) => [
                'name' => $p, 'in' => 'path', 'required' => true,
                'schema' => ['type' => 'string'], 'description' => 'Resource identifier (UUIDv7).',
            ], $params);
        }

        if (in_array($verb, ['post', 'put', 'patch'], true)) {
            $op['requestBody'] = [
                'required' => false,
                'content'  => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]],
            ];
        }

        $okCode = ($verb === 'post' && $params === []) ? '201' : '200';
        $op['responses'][$okCode] = [
            'description' => 'Success — Result envelope.',
            'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Success']]],
        ];
        $op['responses']['400'] = $this->problem('Validation or request error.');
        if ($secured) {
            $op['responses']['401'] = $this->problem('Unauthenticated.');
            $op['security']         = [['BearerToken' => []]];
        }
        if ($authz !== []) {
            $op['responses']['403'] = $this->problem('Forbidden — missing permission.');
        }
        if ($rl !== []) {
            $op['responses']['429'] = $this->problem('Rate limit exceeded.');
        }

        $op['x-permissions'] = $authz;

        return $op;
    }

    /** @return array<string,mixed> */
    private function problem(string $description): array
    {
        return [
            'description' => $description,
            'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
        ];
    }

    /**
     * Convert CI route placeholders to named {params}; name derives from the
     * preceding literal segment (singularized) so paths read naturally.
     *
     * @return array{0:string,1:list<string>}
     */
    private function toOpenApiPath(string $path): array
    {
        $segs   = explode('/', $path);
        $out    = [];
        $params = [];
        $used   = [];
        $prev   = 'id';

        foreach ($segs as $seg) {
            if ($seg !== '' && ($seg[0] === '(' || str_contains($seg, '(:'))) {
                $base = ($prev !== '' && $prev !== 'id') ? preg_replace('/s$/', '', $prev) : 'id';
                $name = $base === 'id' ? 'id' : $base . 'Id';
                $n    = $name;
                $i    = 2;
                while (isset($used[$n])) {
                    $n = $name . $i;
                    $i++;
                }
                $used[$n]  = true;
                $params[]  = $n;
                $out[]     = '{' . $n . '}';
            } else {
                $out[] = $seg;
                if ($seg !== '') {
                    $prev = $seg;
                }
            }
        }

        return [implode('/', $out), $params];
    }

    private function tagFromHandler(string $handler): string
    {
        if (preg_match('/WBS\\\\(\w+)\\\\Controllers/', $handler, $m) === 1) {
            return $m[1];
        }

        return str_contains($handler, 'App\\Controllers') ? 'System' : 'General';
    }

    private function humanize(string $method): string
    {
        $words = strtolower((string) preg_replace('/(?<!^)(?=[A-Z])/', ' ', $method));

        return $words === '' ? $method : ucfirst($words);
    }
}
