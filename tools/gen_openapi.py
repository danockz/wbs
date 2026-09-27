#!/usr/bin/env python3
"""Bootstrap generator for public/openapi.json from app/Config/Routes.php.

This mirrors the canonical PHP generator (php spark openapi:generate). It exists
because the PHP toolchain may be unavailable in some environments; the spark
command regenerates the exact same artifact from the live RouteCollection.

Route-derived, so the spec cannot drift from the real routes: no per-action
annotations to maintain.
"""
import json
import re
import sys

ROUTES = "app/Config/Routes.php"
OUT = "public/openapi.json"

VERB_RE = re.compile(r"\$routes->(get|post|put|patch|delete)\(\s*'([^']*)'\s*,\s*'([^']*)'\s*(?:,\s*\[(.*?)\])?\s*\)\s*;")
GROUP_RE = re.compile(r"\$routes->group\(\s*'([^']*)'\s*(?:,\s*\[(.*?)\])?\s*,\s*static function")
# Single filter:  'filter' => 'auth'
FILTER_RE = re.compile(r"'filter'\s*=>\s*'([^']*)'")
# Array of filters: 'filter' => ['auth', 'webcsrf'] — CI applies ALL of them, so
# the canonical PHP generator (getFiltersForRoute) sees each; mirror that here.
FILTER_ARR_RE = re.compile(r"'filter'\s*=>\s*\[([^\]]*)\]")


def parse_filters(opts: str):
    if not opts:
        return []
    m = FILTER_ARR_RE.search(opts)
    if m:
        return re.findall(r"'([^']*)'", m.group(1))
    m = FILTER_RE.search(opts)
    return [m.group(1)] if m else []


def humanize(method_name: str) -> str:
    words = re.sub(r"(?<!^)(?=[A-Z])", " ", method_name).lower()
    return words[0].upper() + words[1:] if words else method_name


def tag_from_handler(handler: str) -> str:
    # \WBS\Identity\Controllers\AuthController::register -> Identity
    m = re.search(r"WBS\\(\w+)\\Controllers", handler)
    if m:
        return m.group(1)
    # Bare app controllers ('Home::index') resolve under App\Controllers at
    # runtime, so the canonical PHP generator tags them 'System'. Mirror that
    # here so the two generators emit the identical artifact.
    if "App\\Controllers" in handler or "\\" not in handler:
        return "System"
    return "General"


def build_path(prefixes, route_path):
    parts = []
    for p in prefixes + [route_path]:
        p = p.strip("/")
        if p:
            parts.append(p)
    return "/" + "/".join(parts)


def to_openapi_path(path, handler):
    """Convert (:segment)/(:num)/(:any) to named {params}; name from preceding literal."""
    segs = path.split("/")
    params = []
    out = []
    prev_literal = "id"
    used = set()
    for seg in segs:
        if seg.startswith("(:"):
            base = re.sub(r"s$", "", prev_literal) if prev_literal not in ("", "id") else "id"
            name = base + "Id" if base != "id" else "id"
            n = name
            i = 2
            while n in used:
                n = f"{name}{i}"
                i += 1
            used.add(n)
            params.append(n)
            out.append("{" + n + "}")
        else:
            out.append(seg)
            if seg:
                prev_literal = seg
    return "/".join(out), params


def parse():
    routes = []
    stack = []  # list of (prefix, [filters])
    with open(ROUTES) as f:
        lines = f.readlines()

    for raw in lines:
        line = raw.rstrip("\n")
        gm = GROUP_RE.search(line)
        if gm:
            stack.append((gm.group(1), parse_filters(gm.group(2) or "")))
            continue
        # A lone group/closure terminator pops the most recent group.
        if line.strip() == "});" and stack:
            stack.pop()
            continue
        vm = VERB_RE.search(line)
        if vm:
            verb, path, handler, opts = vm.group(1), vm.group(2), vm.group(3), vm.group(4) or ""
            prefixes = [s[0] for s in stack]
            filters = []
            for s in stack:
                filters += s[1]
            filters += parse_filters(opts)
            routes.append({
                "verb": verb,
                "path": build_path(prefixes, path),
                "handler": handler,
                "filters": filters,
            })
    return routes


def spec(routes):
    paths = {}
    tags = {}
    perms = set()

    for r in routes:
        oapath, params = to_openapi_path(r["path"], r["handler"])
        handler = r["handler"].lstrip("\\")
        # CI's RouteCollection resolves a bare controller against the default
        # namespace (App\Controllers) before the PHP generator sees it; the raw
        # route table only has the short name. Qualify bare handlers so the
        # `Handler` description matches the canonical PHP artifact exactly.
        if "\\" not in handler and "::" in handler:
            handler = "App\\Controllers\\" + handler
        ctrl, _, method = handler.partition("::")
        method = method.split("/")[0]
        tag = tag_from_handler(r["handler"])
        tags.setdefault(tag, True)

        filters = r["filters"]
        secured = any(fl == "auth" or fl.startswith("authorize:") for fl in filters)
        authz = [fl.split(":", 1)[1] for fl in filters if fl.startswith("authorize:")]
        rl = [fl.split(":", 1)[1] for fl in filters if fl.startswith("ratelimit:")]
        for a in authz:
            perms.add(a)

        op = {
            "tags": [tag],
            "operationId": f"{method}_{r['verb']}_" + re.sub(r"[^A-Za-z0-9]+", "_", r["path"]).strip("_"),
            "summary": f"{humanize(method)}",
            "description": f"Handler `{ctrl}::{method}`.",
            "responses": {},
        }

        desc_bits = []
        if authz:
            desc_bits.append("Requires permission(s): " + ", ".join(f"`{p}`" for p in authz) + ".")
        elif any(fl == "auth" for fl in filters):
            desc_bits.append("Requires an authenticated session or API token.")
        if rl:
            desc_bits.append("Rate-limited by policy: " + ", ".join(f"`{p}`" for p in rl) + ".")
        if desc_bits:
            op["description"] += "\n\n" + " ".join(desc_bits)

        if params:
            op["parameters"] = [{
                "name": p, "in": "path", "required": True,
                "schema": {"type": "string"},
                "description": "Resource identifier (UUIDv7).",
            } for p in params]

        if r["verb"] in ("post", "put", "patch"):
            op["requestBody"] = {
                "required": False,
                "content": {"application/json": {"schema": {"type": "object", "additionalProperties": True}}},
            }

        # Responses — uniform Result envelope.
        ok_code = "201" if (r["verb"] == "post" and not params) else "200"
        op["responses"][ok_code] = {
            "description": "Success — Result envelope.",
            "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Success"}}},
        }
        op["responses"]["400"] = {"description": "Validation or request error.",
                                  "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Problem"}}}}
        if secured:
            op["responses"]["401"] = {"description": "Unauthenticated.",
                                      "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Problem"}}}}
            op["security"] = [{"BearerToken": []}]
        if authz:
            op["responses"]["403"] = {"description": "Forbidden — missing permission.",
                                      "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Problem"}}}}
        if rl:
            op["responses"]["429"] = {"description": "Rate limit exceeded.",
                                      "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Problem"}}}}

        paths.setdefault(oapath, {})[r["verb"]] = op

    tag_desc = {
        "Identity": "Registration, login, MFA, social sign-in, API tokens.",
        "Groups": "Organizational group hierarchy.",
        "Geo": "Geo resolution, reverse-geocoding, and managed venues.",
        "Referrals": "Cloaked referral links, clicks, attribution, sponsorship.",
        "Events": "Event lifecycle, registration, and check-in.",
        "Contributions": "VBCS causes, contributions, refunds, metrics, partnership, commitments.",
        "Courses": "Learning courses, lessons, enrolment.",
        "Notifications": "Preference-gated notifications and campaigns.",
        "Announcements": "Targeted in-app announcements with optional notify, maker-checker, and must-ack.",
        "Integrations": "UPAF provider catalog, connections, credentials, OAuth.",
        "Community": "Feeds, posts, comments, reactions, moderation.",
        "Streaming": "Stream orchestration, overlays, and live engagement.",
        "Meetings": "Provider meeting/webinar integrations.",
        "Gamification": "Points, leaderboards, achievements, streaks, ranks.",
        "Reporting": "Dashboards and exports.",
        "Admin": "Platform settings, feature flags, group config.",
        "System": "Health and service metadata.",
    }

    doc = {
        "openapi": "3.1.0",
        "info": {
            "title": "Win–Build–Send Platform API",
            "version": "1.0.0",
            "description": (
                "Canonical unified web/API for the WBS Membership, Capacity-Building & "
                "Community Platform.\n\n"
                "There is ONE canonical URL per resource (no `/api` tree); JSON vs. HTML is "
                "chosen by representation negotiation. Every JSON response uses a uniform "
                "envelope: successes carry `data` (+ optional `meta`); errors follow RFC 9457 "
                "Problem Details.\n\n"
                "This spec is generated from the route table — it cannot drift from the real routes."
            ),
        },
        "servers": [{"url": "/", "description": "Same-origin canonical endpoints"}],
        "tags": [{"name": t, "description": tag_desc.get(t, "")} for t in sorted(tags)],
        "paths": dict(sorted(paths.items())),
        "components": {
            "securitySchemes": {
                "BearerToken": {
                    "type": "http", "scheme": "bearer", "bearerFormat": "opaque",
                    "description": "Session reference or API access token (prefix `wbsat_`) as a Bearer credential.",
                }
            },
            "schemas": {
                "Success": {
                    "type": "object",
                    "properties": {
                        "data": {"description": "Result payload (object, array, or scalar wrapper)."},
                        "meta": {"type": "object", "additionalProperties": True,
                                 "description": "Optional metadata (pagination, dedupe flags, etc.)."},
                    },
                    "required": ["data"],
                },
                "Problem": {
                    "type": "object",
                    "description": "RFC 9457 problem details.",
                    "properties": {
                        "type": {"type": "string", "default": "about:blank"},
                        "title": {"type": "string", "description": "Stable error code."},
                        "status": {"type": "integer"},
                        "detail": {"type": "string", "description": "Human-readable / message key."},
                        "errors": {"type": "object", "additionalProperties": True},
                        "meta": {"type": "object", "additionalProperties": True},
                    },
                    "required": ["title", "status"],
                },
            },
        },
        "x-permissions": sorted(perms),
    }
    return doc


def main():
    routes = parse()
    doc = spec(routes)
    with open(OUT, "w") as f:
        json.dump(doc, f, indent=2)
    op_count = sum(len(v) for v in doc["paths"].values())
    print(f"routes parsed: {len(routes)}")
    print(f"paths: {len(doc['paths'])}  operations: {op_count}  tags: {len(doc['tags'])}  permissions: {len(doc['x-permissions'])}")


if __name__ == "__main__":
    sys.exit(main())
