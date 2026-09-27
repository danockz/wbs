<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

use Throwable;

/**
 * Conformance harness every custom adapter MUST pass before it can be registered
 * (SRS FR-INT-013 — "documented custom-adapter SDK and contract-test suite").
 *
 * The suite drives a live adapter INSTANCE through the SDK contract using safe,
 * offline fixtures (no real network, no real credentials) and asserts the
 * behavioural guarantees the platform relies on. It is deliberately runnable
 * both in CI (as PHPUnit fixtures wrapping it) AND at registration time (the
 * RegistrationService gates on {@see ContractReport::passed()}), so an adapter's
 * conformance is proven the same way in both places.
 *
 * The checks:
 *   C1  manifest is self-valid (canonical vocab, HTTPS allowlist, healthCheck).
 *   C2  manifest is deterministic (same value across calls) — safe to sign/publish.
 *   C3  every declared capability is actually invokable (no OP_NOT_IMPLEMENTED).
 *   C4  an UNDECLARED operation is rejected, never silently handled.
 *   C5  perform() never throws for an ordinary provider error — it returns fail().
 *   C6  metric-style results carry an honest exactness tag.
 *   C7  no obvious secret material leaks into a result payload.
 *   C8  healthCheck is invokable and returns a well-formed result.
 */
final class ContractTestSuite
{
    /** Keys whose presence in a result payload suggests a leaked secret. */
    private const SECRET_HINTS = ['secret', 'token', 'password', 'api_key', 'apikey', 'client_secret', 'private_key', 'stream_key'];

    /**
     * Run the suite against an adapter instance.
     *
     * @param array<string,array<string,mixed>> $sampleParams optional per-op sample params
     */
    public function run(CustomAdapter $adapter, array $sampleParams = []): ContractReport
    {
        $checks   = [];
        $manifest = $adapter->manifest();

        // C1 — manifest validity.
        $errors   = $manifest->validate();
        $checks[] = new ContractCheck(
            'C1_manifest_valid',
            $errors === [],
            $errors === [] ? 'manifest passes canonical + safety validation' : implode('; ', $errors),
        );

        // C2 — manifest determinism (must be signable/publishable).
        $deterministic = $adapter->manifest()->canonicalJson() === $manifest->canonicalJson();
        $checks[]      = new ContractCheck(
            'C2_manifest_deterministic',
            $deterministic,
            $deterministic ? 'manifest() is stable across calls' : 'manifest() returned different values across calls',
        );

        // C4 — undeclared operation rejected. (Run before C3 so a broken guard
        // is visible even if declared ops fail.)
        $undeclared = $this->firstUndeclaredOp($manifest);
        $c4Res      = $this->safePerform($adapter, $undeclared, []);
        $c4Ok       = $c4Res instanceof OperationResult && ! $c4Res->ok;
        $checks[]   = new ContractCheck(
            'C4_undeclared_rejected',
            $c4Ok,
            $c4Ok ? "undeclared op '{$undeclared}' correctly rejected" : "undeclared op '{$undeclared}' was NOT rejected",
        );

        // C3/C5/C6/C7 — exercise every declared capability.
        foreach ($manifest->capabilities as $op) {
            $params = $sampleParams[$op] ?? [];
            $res    = $this->safePerform($adapter, $op, $params);

            // C5 — no throw.
            if (! $res instanceof OperationResult) {
                $checks[] = new ContractCheck("C5_no_throw::{$op}", false, "perform('{$op}') threw: " . (string) $res);
                continue;
            }
            $checks[] = new ContractCheck("C5_no_throw::{$op}", true, "perform('{$op}') returned an OperationResult", false);

            // C3 — declared op must be implemented (fail is fine, "not implemented" is not).
            $implemented = $res->errorCode !== 'OP_NOT_IMPLEMENTED' && $res->errorCode !== 'OP_NOT_DECLARED';
            $checks[]    = new ContractCheck(
                "C3_capability_invokable::{$op}",
                $implemented,
                $implemented ? "declared op '{$op}' is invokable" : "declared op '{$op}' is not implemented",
            );

            // C6 — honest exactness tag.
            $exactnessOk = in_array($res->exactness, ['exact', 'estimated', 'unavailable'], true);
            $checks[]    = new ContractCheck(
                "C6_honest_exactness::{$op}",
                $exactnessOk,
                $exactnessOk ? "exactness '{$res->exactness}' is valid" : "invalid exactness '{$res->exactness}'",
            );

            // C7 — no leaked secret material.
            $leak     = $this->findSecretLeak($res->data);
            $checks[] = new ContractCheck(
                "C7_no_secret_leak::{$op}",
                $leak === null,
                $leak === null ? 'no secret-like keys in result' : "result exposes secret-like key '{$leak}'",
            );
        }

        // C8 — healthCheck specifically.
        $health   = $this->safePerform($adapter, 'healthCheck', []);
        $c8Ok     = $health instanceof OperationResult && $health->ok;
        $checks[] = new ContractCheck(
            'C8_healthcheck',
            $c8Ok,
            $c8Ok ? 'healthCheck returns ok' : 'healthCheck did not return a successful result',
        );

        return new ContractReport($manifest->code, $manifest->version, $checks);
    }

    /**
     * Invoke perform() defensively: return the OperationResult, or the throwable
     * message string when the adapter throws (which is itself a C5 failure).
     *
     * @param array<string,mixed> $params
     */
    private function safePerform(CustomAdapter $adapter, string $op, array $params): OperationResult|string
    {
        $request = new OperationRequest(
            operation: $op,
            params: $params,
            config: [],
            // Deterministic fixture secret resolver: never a real credential.
            secretResolver: static fn (string $slot): string => 'fixture-secret::' . $slot,
        );

        try {
            return $adapter->perform($request);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private function firstUndeclaredOp(AdapterManifest $manifest): string
    {
        // A reserved sentinel that is never a canonical operation.
        $candidate = '__contract_probe_undeclared__';

        return $manifest->supports($candidate) ? $candidate . '_x' : $candidate;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function findSecretLeak(array $data): ?string
    {
        foreach ($data as $key => $value) {
            $k = strtolower((string) $key);
            foreach (self::SECRET_HINTS as $hint) {
                if (str_contains($k, $hint)) {
                    return (string) $key;
                }
            }
            if (is_array($value)) {
                $nested = $this->findSecretLeak($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
