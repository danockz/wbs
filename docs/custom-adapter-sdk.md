# Custom-Adapter SDK & Contract-Test Suite (FR-INT-013)

> The **New provider extension promise.** The UPAF supports **no-code** onboarding
> for any provider that conforms to a certified protocol/capability profile. When
> a future provider has **non-conforming** behaviour that cannot be expressed as a
> declarative connector profile, this **documented custom-adapter SDK** and
> **contract-test suite** let it be added as a **versioned, signed, reviewed
> module** — *without changing any core business module or any group's
> configuration workflow*. This is an **extension of the adapter catalogue, not
> unrestricted runtime code injection.**

---

## 1. When do I need a custom adapter?

| Situation | Use |
|-----------|-----|
| Provider speaks one of the certified UPAF families (OIDC, hosted checkout, REST notification, signed webhook, meeting/stream link, metric polling…) with mappable request/response shapes | **No-code connector profile** (`ProfileService`) — no code at all. |
| Provider's behaviour cannot be mapped by a declarative profile (bespoke handshake, multi-step flow, non-standard signing, SDK-only API) | **Custom adapter** (this SDK). |

A custom adapter is still **reviewed code** you ship in the repo and **allowlist**
in `Integrations/Config/Services::customAdapterRegistry()`. Shipping + allowlisting
the class is the reviewed act — the platform never instantiates an arbitrary class
name from the database.

---

## 2. Anatomy of an adapter

Everything an adapter can do is **declared** in an `AdapterManifest` and enforced
against it at runtime. Implement `CustomAdapter` (or extend
`AbstractCustomAdapter` for the ergonomic base):

```php
final class AcmePayAdapter extends AbstractCustomAdapter
{
    protected function buildManifest(): AdapterManifest
    {
        return new AdapterManifest(
            code: 'acme_pay_v1',            // lower_snake, unique + versioned
            version: 1,
            category: 'payment',            // ⊆ Canonical::CATEGORIES
            family: 'hosted_payment_checkout', // ⊆ Canonical::FAMILIES
            displayName: 'Acme Pay',
            capabilities: ['createCheckout', 'queryPayment', 'verifyWebhook', 'healthCheck'],
            credentialFields: ['api_key', 'webhook_secret'],
            approvedHosts: ['api.acmepay.com'],   // HTTPS egress allowlist (no IPs)
        );
    }

    protected function opCreateCheckout(OperationRequest $r): OperationResult
    {
        $apiKey = $r->secret('api_key');            // scoped to this call only
        if ($apiKey === null) {
            return OperationResult::fail('NO_CREDENTIAL', 'api_key not configured');
        }
        // ... call https://api.acmepay.com/... via the egress-controlled client
        return OperationResult::ok(['checkout_url' => $url, 'provider_ref' => $ref]);
    }

    // one opXxx() per declared capability; healthCheck has a default.
}
```

### The rules the SDK enforces

1. **Declared or nothing.** `perform()` for an operation not in the manifest
   returns `OP_NOT_DECLARED` — the manifest is the hard capability boundary.
2. **No raw credentials, no DB.** An adapter receives an `OperationRequest` with a
   *scoped* `secret(slot)` resolver (backed by `CredentialVault::useSecret` at
   runtime, fixtures under test). Plaintext never reaches application code.
3. **Never throw for provider errors** — return `OperationResult::fail(code, msg)`.
4. **Never return secret material** and **never fabricate** a metric — tag metric
   results with an honest `exactness` (`exact` \| `estimated` \| `unavailable`).
5. **HTTPS allowlist only.** Manifest hosts must be bare hostnames; IP literals,
   `localhost`, schemes/paths and wildcards are rejected (SSRF/egress control).

---

## 3. The contract-test suite

`ContractTestSuite::run($adapter)` drives an adapter instance through the contract
using **offline fixtures** (no network, no real credentials) and returns a
`ContractReport`. An adapter is **certified only when every critical check passes**:

| Check | Guarantee |
|-------|-----------|
| `C1_manifest_valid` | Manifest passes canonical vocabulary + safety validation. |
| `C2_manifest_deterministic` | `manifest()` is stable — safe to sign & publish. |
| `C3_capability_invokable::{op}` | Every declared op is actually implemented. |
| `C4_undeclared_rejected` | An undeclared op is rejected, never silently handled. |
| `C5_no_throw::{op}` | `perform()` never throws for an ordinary error. |
| `C6_honest_exactness::{op}` | Metric results carry a valid exactness tag. |
| `C7_no_secret_leak::{op}` | No secret-like keys appear in a result payload. |
| `C8_healthcheck` | `healthCheck` is invokable and returns a well-formed result. |

The same suite runs both in **CI** (`tests/unit/CustomAdapterSdkTest.php`) and at
**registration time** (the service gates activation on it), so conformance is
proven identically in both places.

---

## 4. Registration lifecycle (versioned · signed · reviewed)

`CustomAdapterService` mirrors the connector-profile certification path. Every
step appends to the append-only `custom_adapter_reviews` trail.

```
register        POST /integrations/custom-adapters            {impl_class}
  → draft         (manifest taken from the adapter, validated, stored)

contract-test   POST /integrations/custom-adapters/{id}/contract-test
  → contract_tested   (runs the suite; on PASS the manifest is SIGNED)

advance         POST /integrations/custom-adapters/{id}/advance   {to_status: security_review}
  → security_review

approve         POST /integrations/custom-adapters/{id}/approve
  → approved      (checker ≠ submitter — segregation of duties;
                   requires certified + a valid signature)

activate        POST /integrations/custom-adapters/{id}/activate
  → active        (re-verifies the signature, then PUBLISHES the manifest into
                   provider_adapter_catalog as type='custom_adapter')

revoke          POST /integrations/custom-adapters/{id}/revoke
  → revoked       (deprecates the published catalogue row; history preserved)
```

All writes are gated by the `provider.configure` permission. Read endpoints:
`GET /integrations/custom-adapters`, `.../allowlist`, `.../{id}`.

### Signing

`ManifestSigner` signs the **canonical JSON** of the manifest with
`HMAC-SHA256` keyed by the platform `KeyProvider` (the same switchable
env/KMS/Vault seam as `SecretBox`). Signature format `v1:{keyId}:{b64url(mac)}`
embeds the key epoch, so old signatures stay verifiable across rotation.
Verification is constant-time and **fail-closed** (malformed/unknown-key/mismatch
→ invalid). A certified manifest therefore cannot be altered post-review without
invalidating its signature — approval and activation both re-verify it.

---

## 5. What does NOT change

Onboarding a custom adapter touches **only**:

- the adapter class + its allowlist entry (reviewed code), and
- data rows (`custom_adapters`, `custom_adapter_reviews`, and on activation a
  `provider_adapter_catalog` row).

No core business module changes, and no group's configuration workflow changes —
once published, the rest of the platform (catalogue UI, FR-INT-012 fallback
matrix, connections, credential vault) consumes a custom adapter **exactly** like
a built-in one, because they all read from the same declared-capability catalogue.

---

## 6. Reference implementation

`app/Modules/Integrations/Sdk/Examples/ExampleRestNotificationAdapter.php` is a
complete, deterministic worked example (a hypothetical REST notification
provider). It ships allowlisted, so you can exercise the full lifecycle against
it out of the box:

```
POST /integrations/custom-adapters                 {"impl_class":"WBS\\Integrations\\Sdk\\Examples\\ExampleRestNotificationAdapter"}
POST /integrations/custom-adapters/{id}/contract-test
POST /integrations/custom-adapters/{id}/advance    {"to_status":"security_review"}
POST /integrations/custom-adapters/{id}/approve    (as a different user)
POST /integrations/custom-adapters/{id}/activate
```
