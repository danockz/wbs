<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

/**
 * Ergonomic base for custom adapters (SRS FR-INT-013).
 *
 * Handles the boilerplate every conforming adapter needs so a provider author
 * writes only the provider-specific bits:
 *  - guards each call against the manifest (an undeclared op is rejected before
 *    the adapter's own code runs — the manifest is the enforced boundary),
 *  - provides a default `healthCheck` implementation, and
 *  - dispatches declared ops to `op<Name>()` methods when present, so authors
 *    can implement `opCreateCheckout(OperationRequest): OperationResult` etc.
 *
 * Subclasses supply {@see manifest()} and one method per declared capability.
 */
abstract class AbstractCustomAdapter implements CustomAdapter
{
    private ?AdapterManifest $cached = null;

    abstract protected function buildManifest(): AdapterManifest;

    final public function manifest(): AdapterManifest
    {
        return $this->cached ??= $this->buildManifest();
    }

    public function perform(OperationRequest $request): OperationResult
    {
        $op = $request->operation;

        // The manifest is the hard boundary: never run code for an undeclared op.
        if (! $this->manifest()->supports($op)) {
            return OperationResult::fail(
                'OP_NOT_DECLARED',
                "operation '{$op}' is not declared in the adapter manifest",
            );
        }

        if ($op === 'healthCheck' && ! method_exists($this, 'opHealthCheck')) {
            return $this->defaultHealthCheck($request);
        }

        $method = 'op' . ucfirst($op);
        if (method_exists($this, $method)) {
            /** @var OperationResult */
            return $this->{$method}($request);
        }

        return OperationResult::fail(
            'OP_NOT_IMPLEMENTED',
            "operation '{$op}' is declared but not implemented by " . static::class,
        );
    }

    /** Adapters may override for a real probe; default reports reachable=declared. */
    protected function defaultHealthCheck(OperationRequest $request): OperationResult
    {
        return OperationResult::ok([
            'adapter'   => $this->manifest()->code,
            'version'   => $this->manifest()->version,
            'declared'  => $this->manifest()->capabilities,
        ]);
    }
}
