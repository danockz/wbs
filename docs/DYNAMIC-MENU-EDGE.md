# Dynamic menu — zero-server-burden delivery (signed word + edge cache)

The top rung: the app tier does **essentially nothing** between authority changes.
Two mechanisms make that real, both now implemented:

1. **Signed client-held word** (`SignedWord`) — the browser holds an 8-byte
   capability word as a tamper-evident token, echoes it back, and the origin trusts
   it (skipping the DB role reads AND derivation) after a constant-time MAC check.
2. **Edge cache contract** — the origin emits precise `Cache-Control` / `ETag` /
   `Surrogate-Key` headers so a CDN/reverse proxy answers revalidations (304) and
   serves the shared authority bundle without touching origin.

Security stays intact throughout: the word drives **display only**; every route
keeps its `authorize:` filter, so a forged/stale word can at most surface a dead
link the PDP then denies.

---

## Origin header contract (what the app already sends)

| Endpoint | Cache-Control | ETag | Surrogate-Key |
|----------|---------------|------|---------------|
| `GET /me/menu` (+`?bundle=1`) | `private, max-age=0, must-revalidate` | `"m<grantVer>.<scope>.<catalogVer>.<roleTblVer>.<locale>"` | `menu-user-<id> menu-org-<org>` |
| `GET /me/menu/authority` | `public, max-age=60, s-maxage=86400, stale-while-revalidate=86400` | `"auth.a<roleTblVer>.<catalogVer>.<locale>"` | `menu-authority org-<org> menu-authority-<locale>-org-<org>` |

Both ETags carry a **`<locale>`** term and both responses add `Vary: … Accept-Language`
(the per-user response also keeps `Cookie`, which is where `wbs_locale` rides) plus a
`Content-Language` header. Category **and** item labels are localized at build time, so
the locale is part of cache identity: a French menu never shares a cache entry with an
English one, and a language switch revalidates immediately even when grants, scope,
catalog and roles are all unchanged. See `docs/DYNAMIC-MENU-I18N.md`.

- **Per-user menu** is `private` (never shared) but **revalidatable**: the browser
  and any private edge answer `If-None-Match` with **304, empty body** until a
  version stamp moves. Origin does zero render on the common navigation.
- **Authority bundle** is tenant-wide and **non-secret** (no per-user data), so it
  is `public` and shareable: one copy per org lives at the CDN (`s-maxage`), and
  every user in the tenant is served from the edge. This is what removes navigation
  traffic from the origin at 500k concurrency.
- The signed word travels in the `X-Menu-Word` response header and (for the bundle)
  the `signedWord` JSON field; the client echoes it back in the `X-Menu-Word`
  request header. `Vary` includes `X-Menu-Word` so caches never cross-serve.

---

## Client loop (browser / SPA)

```text
1. On login, fetch GET /me/menu/authority  (cached at edge; ~123 B table + catalog).
2. Derive word locally: word = OR of roleWords[r] for the user's roles.
   (Or take the signed word from GET /me/menu once and cache it.)
3. Render locally: for each item, show iff (word & item.mask) === item.mask.
4. Scope switch = re-mask locally with that scope's word. NO round trip.
5. Periodically (or on navigation) send If-None-Match with the stored ETag AND
   X-Menu-Word with the stored signed word:
      - 304  -> nothing changed; keep rendering locally. (Edge answers; origin idle.)
      - 200  -> versions moved; adopt the new word/bundle from the response.
```

Between authority changes the origin sees only conditional requests, which the edge
absorbs. A grant change bumps a version stamp → ETag changes → next revalidation
returns 200 once, then back to 304s.

---

## Edge configs (copy-paste; pick your layer)

### Cloudflare (Cache Rules + Workers KV optional)
```
# Cache Rule: authority bundle is shareable per org.
When incoming request matches:  URI Path equals "/me/menu/authority"
Then:
  Cache eligibility: Eligible for cache
  Edge TTL: Respect origin (s-maxage=86400)
  Cache key: include Host + URI + Header "Cookie:org"   # or a tenant cookie/JWT claim
  Serve stale while revalidate: On

# Per-user menu: let the browser revalidate; do not share.
When URI Path equals "/me/menu":
  Cache eligibility: Bypass cache            # private; ETag/304 handled browser<->origin
```
Targeted purge on a grant change: `POST /purge` with `Surrogate-Key: menu-user-<id>`
(via Cloudflare Cache-Tag if on Enterprise) — otherwise rely on the ETag flip.

### Varnish (VCL)
```vcl
sub vcl_recv {
    # Authority bundle: cacheable, shared per org (org carried in a header/JWT).
    if (req.url ~ "^/me/menu/authority") {
        return (hash);
    }
    # Per-user menu: pass, but keep conditional revalidation working.
    if (req.url ~ "^/me/menu") {
        return (pass);
    }
}
sub vcl_hash {
    if (req.url ~ "^/me/menu/authority") {
        hash_data(req.http.X-Org-Id);   # one cached object per tenant
    }
}
sub vcl_backend_response {
    if (bereq.url ~ "^/me/menu/authority") {
        set beresp.ttl = 24h;
        set beresp.grace = 24h;         # stale-while-revalidate
    }
}
# Purge by surrogate key on role-permission change:
#   varnishadm "ban obj.http.Surrogate-Key ~ menu-authority"
```

### Nginx (proxy_cache)
```nginx
proxy_cache_path /var/cache/nginx/menu levels=1:2 keys_zone=menu:10m inactive=1d;

location = /me/menu/authority {
    proxy_pass http://app;
    proxy_cache menu;
    proxy_cache_key "$host$request_uri$http_x_org_id";  # per tenant
    proxy_cache_valid 200 24h;
    proxy_cache_use_stale updating;                       # SWR
    add_header X-Cache-Status $upstream_cache_status;
}
location = /me/menu {
    proxy_pass http://app;
    proxy_cache off;            # private; ETag/304 handled by client<->origin
}
```

### Fastly (VCL, surrogate keys)
```vcl
sub vcl_recv {
  if (req.url.path == "/me/menu/authority") { return(lookup); }
  if (req.url.path == "/me/menu")           { return(pass); }
}
# Origin already sets Surrogate-Key: "menu-authority org-<org>".
# Purge one tenant on a role-permission edit:
#   PURGE https://api.fastly.com/service/<id>/purge/org-<org>
```

---

## Invalidation summary (all O(1), no scans)

| Change | Stamp that moves | Effect |
|--------|------------------|--------|
| A user's grants change | `grantVersion` (assignment-set hash) | their menu ETag flips; next revalidate = one 200; purge `menu-user-<id>` for instant |
| Role→permission edit | role-table version (`roleTblVer`) | ALL menus + authority bundle ETag flip; purge `menu-authority`/`org-<org>` |
| Deploy changes catalog | `catalogVersion` (structure fingerprint) | every ETag flips fleet-wide (see DYNAMIC-MENU-PROTOTYPE.md) |
| Signed word tampered/stale/expired | MAC / version / `exp` | rejected on verify → origin re-derives once, re-signs |

No counters, no cache enumeration; freshness is always a version compare.

---

## Cost at 500k concurrent

- **Navigation:** served by browser (local render) + edge (304 / shared authority
  bundle). Origin requests ≈ **0** between authority changes.
- **Origin work when it does run:** verify a signed word (one HMAC) OR derive from
  roles (a few ORs) — no per-user storage either way.
- **Edge storage:** one ~123 B authority object per tenant; per-user menus are not
  stored at the edge (they revalidate browser↔origin, and even that is a 304).

## Verify (sandbox)

```bash
php app/Modules/Shared/Navigation/tests/menu_signedword_test.php   # 20/20 (mint/verify/tamper/expiry/rotation)
php app/Modules/Shared/Navigation/tests/menu_headers_test.php      # edge header contract
```
