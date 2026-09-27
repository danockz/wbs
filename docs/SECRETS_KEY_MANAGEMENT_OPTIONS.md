# Tier 1 — Key Management Options (Decision Menu)

Status: **reference only, not yet implemented.** Tier 0 (strip weak fallbacks,
fail-fast on missing prod secrets) is deliberately deferred per owner decision.
This document exists so that when the need to switch arises, the choice is a
config/provider decision — not a redesign.

---

## 0. Why switching is cheap in this codebase (the seams already exist)

Two facts about the current code make provider swap low-risk:

1. **One construction path.** Every consumer builds its key the same way:

   ```php
   $key = SecretBox::keyFromEnv(getenv('encryption.key') ?: getenv('ENCRYPTION_KEY') ?: '');
   new SecretBox($key);
   ```

   Call sites today: `Integrations\Config\Services::credentialVault()` and
   `Identity\Config\Services::mfa()`. Nothing else touches raw key material.
   Abstracting key acquisition means editing **those factories only** — no
   service, controller, or model changes.

2. **The stored blob is already key-versioned.** `SecretBox::encrypt()` emits:

   ```
   {keyId}:{base64( VERSION(1B) || nonce(24B) || cipher )}
   ```

   `keyId` defaults to `k1`. Because the identifier travels *with* the
   ciphertext, decrypt can look up the correct key per row. That is exactly what
   you need for **rotation with coexistence** and for **multi-provider
   migration** (old rows `k1:` decrypt with the legacy key; new rows `k2:`/`aws:`
   decrypt with the new one) — no bulk re-encryption required to cut over.

The crypto primitive itself (libsodium `crypto_secretbox`, random nonce, AAD
binding, `sodium_memzero`) does **not** change under any option below. What
changes is **where the 32-byte data key comes from and how it is protected at
rest.**

---

## 1. The abstraction (ALREADY IN PLACE ✅)

A single interface decouples "give me a usable data key" from "who guards it".
**This seam is now implemented** (behavior-identical to before), so adding a
managed provider later is a drop-in with no call-site changes:

- `app/Modules/Shared/Security/KeyProvider.php` — the interface:

  ```php
  interface KeyProvider
  {
      public function activeKeyId(): string;      // keyId to tag new ciphertext
      public function keyFor(string $keyId): string; // resolve 32-byte data key
  }
  ```

- `app/Modules/Shared/Security/EnvKeyProvider.php` — the default implementation.
  Reads the same `encryption.key` / `ENCRYPTION_KEY` env, uses the same
  `keyFromEnv` normalization, same default keyId `k1` → **zero runtime change.**
- `SecretBox` now accepts **either** a raw 32-byte string (legacy, unchanged)
  **or** a `KeyProvider`. Encrypt tags with `activeKeyId()`; decrypt reads each
  blob's own keyId prefix and resolves it via `keyFor()`.
- `WBS\Shared\Config\Services::keyProvider()` is the single binding to swap.
  Both consumers now build through it:

  ```php
  new SecretBox(SharedServices::keyProvider());   // CredentialVault + MfaService
  ```

- Locked by `tests/unit/SecretBoxKeyProviderTest.php` (legacy round-trip,
  provider round-trip, env-interop, cross-key rotation, unknown-keyId failure).

**To switch providers later:** implement `KeyProvider` for your chosen option
below and return it from `Services::keyProvider()` — e.g. selected by a
`KEY_PROVIDER=env|aws-kms|gcp-kms|azure-kv|vault` env var. Nothing else changes.

> Envelope encryption note: for the KMS options the provider does **not** hand
> back the master key. It returns a **data key (DEK)** that KMS decrypts from a
> stored wrapped blob. The DEK is what `SecretBox` uses; the master key never
> leaves the HSM.

---

## 2. The options

### Option A — Env / file (current behaviour, formalized)
- **What:** key from `encryption.key`, injected by the platform (env var, mounted
  secret file, or orchestrator secret).
- **Custody:** whoever can read the process env / file can read the key.
- **Rotation:** manual; add `k2`, flip `activeKeyId`, lazily re-encrypt on next
  write, retire `k1` once no `k1:` rows remain.
- **Pros:** zero new infra, zero latency, works offline/on-prem.
- **Cons:** single point of total compromise; no HSM; audit is only as good as
  the host. **Weakest custody.**
- **Choose when:** earliest deploys, air-gapped/on-prem, or no cloud commitment.

### Option B — AWS KMS (envelope encryption)  ← recommended default if on AWS
- **What:** a CMK in KMS wraps per-secret/per-epoch DEKs. Provider calls
  `GenerateDataKey` (encrypt path) and `Decrypt` (read path); cache DEKs briefly.
- **Custody:** master key never leaves the KMS HSM; IAM-scoped; every use is in
  CloudTrail.
- **Rotation:** automatic annual CMK rotation, or app-level DEK epochs via
  `keyId`. Coexistence is free thanks to the `keyId` tag.
- **Pros:** managed HSM, fine-grained IAM, full audit, low ops. **Lowest-friction
  strong option.**
- **Cons:** AWS lock-in; per-call latency (mitigate with DEK caching); KMS
  availability becomes a dependency.
- **Choose when:** you host on AWS and want the best effort/security ratio.

### Option C — GCP KMS / Azure Key Vault
- **What:** same envelope pattern as B, different cloud. `EncryptDataKey`/
  `Decrypt` (GCP) or wrap/unwrap key + Managed HSM (Azure).
- **Custody / audit / rotation:** equivalent to B, native to that cloud's IAM and
  logging.
- **Pros/Cons:** mirror B; pick by whichever cloud you actually run in.
- **Choose when:** your platform is GCP or Azure.

### Option D — HashiCorp Vault
- **Two sub-modes:**
  - **Transit engine** — Vault encrypts/decrypts DEKs (encryption-as-a-service);
    matches the envelope pattern, cloud-agnostic.
  - **KV v2** — Vault stores the secrets themselves; app fetches at boot/lease.
- **Bonus:** dynamic, short-lived DB credentials and leased secrets (rotate the
  DB password automatically), which none of the KMS options give you.
- **Pros:** multi-cloud / on-prem neutral; richest secret lifecycle; strong audit.
- **Cons:** **you operate Vault** (HA, unseal, upgrades) — highest ops burden.
- **Choose when:** multi-cloud or hybrid, or you specifically want dynamic leased
  credentials, and you can run Vault properly.

### Option E — Cloud secrets *manager* (AWS Secrets Manager / GCP Secret Manager / Azure App Config)
- **What:** stores the *secret values* (and can auto-rotate them); distinct from a
  *key* manager. Useful for DB passwords, provider API keys, webhook secrets —
  i.e. the `.env` fallbacks flagged in Tier 0 — rather than the SecretBox master
  key.
- **Note:** complements, not replaces, B/C/D. Often: Secrets Manager for
  credentials **+** KMS for the encryption master key.
- **Choose when:** you want managed rotation of the many app secrets, independent
  of the envelope-encryption key.

---

## 3. Quick comparison

| Option | Custody / HSM | Ops burden | Cloud lock-in | Dynamic creds | Audit trail |
|---|---|---|---|---|---|
| A Env/file | none | none | no | no | host-only |
| B AWS KMS | HSM | low | AWS | no | CloudTrail |
| C GCP/Azure KMS | HSM | low | GCP/Azure | no | native |
| D Vault | soft/HSM opt | **high** | no | **yes** | Vault audit |
| E Secrets Manager | n/a (values) | low | per cloud | rotation | native |

---

## 4. Recommendation path (when the need arises)

1. **Single cloud, want strong + easy:** Option **B/C** (KMS envelope) as the
   `SecretBox` key provider, optionally **E** for the other app secrets.
2. **Multi-cloud / on-prem / want leased DB creds:** Option **D** (Vault Transit
   for keys, KV or DB engine for credentials).
3. **Not ready for infra yet:** stay on **A**, but do Tier 0 first (kill weak
   fallbacks, fail-fast, reject non-32-byte keys) — that's the real risk today,
   independent of which manager you later pick.

## 4a. What is wired today (code, not just plan)

The seam is not merely described — the switch is in the code:

- `KeyProvider` (interface) — the seam every call site depends on.
- `EnvKeyProvider` — Option A, the default; behavior-identical to the legacy
  env-key wiring.
- `EnvelopeKeyProvider` — Options B/C/D: holds only **wrapped** DEKs and unwraps
  them through a `KekUnwrapper`, caching each unwrapped DEK in memory. Supports
  multiple key epochs for zero-re-encryption rotation.
- `KekUnwrapper` (interface) — the drop-in point for the managed KEK. A cloud
  provider is *one method*: AWS KMS `Decrypt`, GCP `decrypt`, Azure `unwrapKey`,
  or Vault `transit/decrypt`.
- `LocalKekUnwrapper` — offline libsodium unwrapper so the envelope path is
  fully exercised in dev/test/on-prem (and CI without network).
- `Services::keyProvider()` — selects the provider from `KEY_PROVIDER`
  (`env` | `envelope`), and for `envelope` selects the backend from `KEY_KEK`.

Flip it entirely via environment (see `.env.example`):

```
KEY_PROVIDER=envelope
KEY_KEK=local            # → aws-kms | gcp-kms | azure-kv | vault when adopted
KEY_ACTIVE_ID=k1
KEY_WRAPPED_DEKS=k1=<base64 wrapped DEK>
KEK_MATERIAL=hex2bin:... # local backend only; cloud KEKs never reach the app
```

Adopting a cloud manager = implement one `KekUnwrapper` and add one `match` arm
in `Services::envelopeKeyProvider()`. No call site changes. Tests:
`tests/unit/EnvelopeKeyProviderTest.php`.

## 5. Migration mechanics (identical for any A→B/C/D switch)

1. Add the new `KeyProvider`; keep the old one registered.
2. Set `activeKeyId` to the new key (e.g. `k2` / `aws:1`).
3. New writes tag `k2:`; old `k1:` rows keep decrypting via the legacy key.
4. Optional lazy re-encrypt on next `put()`, or a one-off backfill command.
5. Retire the legacy key once `SELECT ... WHERE cipher LIKE 'k1:%'` returns zero.

No downtime, no big-bang re-encryption — because `keyId` is already in the blob.
