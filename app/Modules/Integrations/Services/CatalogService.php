<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Result;

/**
 * Read access to the deployed adapter catalogue (SRS FR-INT-001/011).
 *
 * The catalogue is code-owned and versioned. The UI is generated from the
 * effective declared capabilities so unavailable operations are hidden/disabled
 * rather than faked.
 */
final class CatalogService
{
    public function __construct(
        private readonly BaseConnection $db,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function activeAdapters(?string $category = null): array
    {
        $q = $this->db->table('provider_adapter_catalog')->where('status', 'active');
        if ($category !== null) {
            $q->where('category', $category);
        }

        return $q->orderBy('category')->orderBy('code')->get()->getResultArray();
    }

    /** Resolve a specific adapter version (latest active if version omitted). */
    public function adapter(string $code, ?int $version = null): Result
    {
        $q = $this->db->table('provider_adapter_catalog')->where('code', $code);
        if ($version !== null) {
            $q->where('version', $version);
        } else {
            $q->where('status', 'active')->orderBy('version', 'DESC');
        }
        $row = $q->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('integration.adapter_not_found', 'ADAPTER_NOT_FOUND');
        }

        return Result::ok($row);
    }

    /**
     * Effective capabilities for an adapter — used to generate honest UI
     * (FR-INT-011): never claim operations the adapter doesn't declare.
     *
     * @return list<string>
     */
    public function capabilities(string $code, ?int $version = null): array
    {
        $res = $this->adapter($code, $version);
        if (! $res->ok) {
            return [];
        }
        $caps = json_decode((string) ($res->data['capabilities'] ?? '[]'), true);

        return is_array($caps) ? array_values($caps) : [];
    }
}
