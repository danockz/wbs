<?php

declare(strict_types=1);

namespace WBS\Integrations\Controllers;

use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Adapter catalogue (SRS FR-INT-001/011). UI is generated from honest declared
 * capabilities.
 */
final class CatalogController extends BaseController
{
    public function index()
    {
        $category = $this->field('category');
        $adapters = IntegrationServices::adapterCatalog()->activeAdapters($category !== null ? (string) $category : null);

        // API clients get JSON (admin-console path); browsers get the bespoke
        // catalogue view — no raw JSON, and richer than the generic console.
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok($adapters),
                'Integration catalog',
                $category !== null ? 'category ' . $category : '',
            );
        }

        return $this->respondWith(
            Result::ok($adapters),
            'WBS\Integrations\Views\catalog',
            null,
            ['adapters' => $adapters],
        );
    }
}
