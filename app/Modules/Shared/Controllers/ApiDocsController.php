<?php

declare(strict_types=1);

namespace WBS\Shared\Controllers;

use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\OpenApiGenerator;

/**
 * Serves the platform's OpenAPI spec and an interactive Swagger-UI docs page
 * (SRS FR-ARC-001/002 — canonical, self-documenting API).
 *
 *   GET /openapi.json  -> the OpenAPI 3.1 spec (static file if present, else
 *                         generated on the fly from the route table)
 *   GET /docs          -> Swagger-UI rendering of that spec
 *
 * The spec is route-derived (see OpenApiGenerator), so it cannot drift from the
 * real routes. This replaces the reference `ApiDocsController` that relied on
 * scanning per-controller `#[OA\...]` attributes we deliberately do not use.
 */
final class ApiDocsController extends BaseController
{
    /** Emit the OpenAPI spec as JSON. */
    public function spec()
    {
        $file = FCPATH . 'openapi.json';
        if (is_file($file)) {
            $json = (string) file_get_contents($file);

            return $this->cacheSafe()
                ->setContentType('application/json')
                ->setBody($json);
        }

        // Fallback: generate live (dev convenience when the static file is absent).
        $spec = (new OpenApiGenerator())->generate();

        return $this->cacheSafe()->setJSON($spec);
    }

    /** Swagger-UI docs page pointed at /openapi.json. */
    public function ui()
    {
        return $this->cacheSafe()
            ->setContentType('text/html; charset=UTF-8')
            ->setBody($this->page());
    }

    private function page(): string
    {
        // Swagger-UI assets load from a CDN: this is a LIVE server route (a full
        // browser navigation), not the sandboxed file-preview iframe, so the CDN
        // resolves normally. The spec itself is same-origin at /openapi.json.
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WBS Platform API — Reference</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
  <style>
    body { margin: 0; }
    .topbar { background:#0b3d2e; color:#fff; padding:14px 22px; font:600 16px system-ui,sans-serif; }
    .topbar span { opacity:.75; font-weight:400; font-size:13px; margin-left:8px; }
  </style>
</head>
<body>
  <div class="topbar">Win–Build–Send Platform API <span>OpenAPI 3.1 · generated from the route table</span></div>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
  <script>
    window.addEventListener('load', function () {
      window.ui = SwaggerUIBundle({
        url: '/openapi.json',
        dom_id: '#swagger-ui',
        deepLinking: true,
        docExpansion: 'none',
        filter: true,
        tryItOutEnabled: true
      });
    });
  </script>
</body>
</html>
HTML;
    }
}
