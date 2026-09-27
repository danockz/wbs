#!/usr/bin/env python3
"""Replace per-view <!DOCTYPE html> shells with Shared/_shell_{open,close}.php."""
from __future__ import annotations

import os
import re
import sys
from pathlib import Path

ROOT = Path("/home/user/wbs-platform")
SHELL_OPEN = ROOT / "app/Modules/Shared/Views/_shell_open.php"
SHELL_CLOSE = ROOT / "app/Modules/Shared/Views/_shell_close.php"

SKIP_NAMES = {
    "app.php",
    "error_exception.php",
    "error_404.php",
    "production.php",
    "_shell_open.php",
    "_shell_close.php",
    "_tokens.php",
    "_locale.php",
    "CertificateRenderer.php",
    "CertificateService.php",
}

HEAD_EXTRA_RE = re.compile(
    r"(<style\b[^>]*>.*?</style>|<meta\s+name=\"robots\"[^>]*>)",
    re.S | re.I,
)
SCRIPT_RE = re.compile(r"<script\b[^>]*>.*?</script>", re.S | re.I)
TITLE_RE = re.compile(r"<title>(.*?)</title>", re.S | re.I)
BODY_RE = re.compile(r"<body\b[^>]*>(.*)</body>", re.S | re.I)
TOKENS_INCLUDE_RE = re.compile(
    r"^[ \t]*<\?php\s+include\s+[^;]*_tokens\.php[^;]*;\s*\?>[ \t]*\n?",
    re.M,
)


def rel_php(from_file: Path, target: Path) -> str:
    rel = os.path.relpath(target, start=from_file.parent)
    return rel.replace("\\", "/")


def convert(path: Path) -> str:
    text = path.read_text(encoding="utf-8")
    if "extend('layouts/app')" in text or "_shell_open.php" in text:
        return "skip"
    if "<!DOCTYPE html>" not in text:
        return "skip"
    if path.name in SKIP_NAMES:
        return "skip"
    if "/tests/" in str(path) or path.parent.name == "Language":
        return "skip"
    if path.parent.name == "Services":
        return "skip"

    pre, rest = text.split("<!DOCTYPE html>", 1)
    tm = TITLE_RE.search(rest)
    title_inner = tm.group(1).strip() if tm else ""
    extras = [m.group(1) for m in HEAD_EXTRA_RE.finditer(rest)]
    bm = BODY_RE.search(rest)
    if not bm:
        return "no-body"
    body = bm.group(1)
    scripts = SCRIPT_RE.findall(body)
    for s in scripts:
        body = body.replace(s, "", 1)

    pre = TOKENS_INCLUDE_RE.sub("", pre)

    rel_open = rel_php(path, SHELL_OPEN)
    rel_close = rel_php(path, SHELL_CLOSE)

    chunks = [pre.rstrip(), ""]
    if title_inner:
        chunks.append("<?php ob_start(); ?>")
        chunks.append(title_inner)
        chunks.append("<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>")
        chunks.append("")
    if extras:
        chunks.append("<?php ob_start(); ?>")
        chunks.append("\n".join(extras))
        chunks.append("<?php $wbsHeadExtra = (string) ob_get_clean(); ?>")
        chunks.append("")
    if scripts:
        chunks.append("<?php ob_start(); ?>")
        chunks.append("\n".join(scripts))
        chunks.append("<?php $wbsFooterExtra = (string) ob_get_clean(); ?>")
        chunks.append("")
    chunks.append(f"<?php include __DIR__ . '/{rel_open}'; ?>")
    chunks.append("")
    chunks.append(body.strip())
    chunks.append("")
    chunks.append(f"<?php include __DIR__ . '/{rel_close}'; ?>")
    chunks.append("")
    path.write_text("\n".join(chunks), encoding="utf-8")
    return "ok"


def main() -> int:
    views = list((ROOT / "app").rglob("*.php"))
    ok = skip = bad = 0
    for p in sorted(views):
        r = convert(p)
        if r == "ok":
            ok += 1
            print("OK", p.relative_to(ROOT))
        elif r == "no-body":
            bad += 1
            print("NO-BODY", p.relative_to(ROOT))
        else:
            skip += 1
    print(f"converted={ok} skipped={skip} bad={bad}")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
