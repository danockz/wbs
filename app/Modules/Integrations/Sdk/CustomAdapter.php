<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

/**
 * The contract a NON-CONFORMING provider implements to be onboarded as a
 * versioned, signed, reviewed custom-adapter MODULE (SRS FR-INT-013).
 *
 * FR-INT-013 promise: any provider that conforms to the certified
 * protocol/capability profiles is onboarded no-code (a connector profile). When
 * a provider's behaviour cannot be expressed as a declarative profile, this SDK
 * lets it ship as a small, reviewed adapter module WITHOUT changing any core
 * business module or any group's configuration workflow. This is an extension of
 * the adapter catalogue — NOT unrestricted runtime code injection: adapter
 * classes are shipped, signed and reviewed like any other code, and every
 * adapter is bounded by:
 *   - its {@see manifest()} (declared capabilities + egress allowlist), and
 *   - the {@see ContractTestSuite} conformance harness it must pass.
 *
 * An adapter declares WHAT it supports (manifest) and implements HOW (perform).
 * It receives no DB handle and no raw credentials — only an {@see OperationRequest}
 * with a scoped secret resolver — so its blast radius is exactly its manifest.
 */
interface CustomAdapter
{
    /**
     * The adapter's self-declared manifest. MUST be deterministic (same value
     * every call) — it is validated, signed and published to the catalogue.
     */
    public function manifest(): AdapterManifest;

    /**
     * Execute one canonical operation. Implementations MUST:
     *  - return OperationResult::fail(...) for an operation the manifest does
     *    not declare (never silently succeed),
     *  - never throw for an ordinary provider error — surface it as fail(),
     *  - never return secret material, and
     *  - tag metric results with an honest exactness.
     */
    public function perform(OperationRequest $request): OperationResult;
}
