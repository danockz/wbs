<?php

declare(strict_types=1);

/**
 * Migration ⇄ runtime schema conformance (DB-FREE).
 *
 * This suite exists because a whole class of production-only failures — columns
 * that the service/seeder layer SELECTs or INSERTs but that never actually make
 * it onto the table — cannot be caught by the in-memory fakes the rest of the
 * standalone suite uses. Two real fatals shipped this way (log 2026-09-16):
 *
 *   - INSERT into `activity_categories (... stage_code ...)` -> Unknown column 'stage_code'
 *   - SELECT `ra`.`scope_mode` FROM `role_assignments`        -> Unknown column 'ra.scope_mode'
 *
 * Root cause: CI4's BaseConnection caches the table/column list per connection
 * (`$dataCache`); raw `query('CREATE …')`/`query('ALTER …')` never invalidate it.
 * On a migrate:refresh the down-phase primes the cache, the up-phase recreates
 * the table, and a guarded `fieldExists()` then reads the STALE list and silently
 * skips its `ADD COLUMN`. The column is simply never added.
 *
 * Two guards, both without a database:
 *
 *   A. CACHE-BUST GUARD — every migration that branches on tableExists()/
 *      fieldExists() MUST call $this->db->resetDataCache() at the top of up() and
 *      down(), so its guards read live schema. This is the direct regression lock.
 *
 *   B. SCHEMA DRIFT — statically reconstruct each table's column set from ALL
 *      migrations (CREATE TABLE + ADD COLUMN, guards ignored because after fix A
 *      they always run) and assert every (table, column) the runtime references
 *      resolves. Seeds the check with the exact columns from the shipped fatals
 *      plus the sibling columns added by the same guarded-ALTER migrations.
 *
 *   php app/Modules/Shared/Database/tests/migration_schema_conformance_test.php
 */

$root = dirname(__DIR__, 5);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}

// ---------------------------------------------------------------------------
// Collect every module migration file, in the GLOBAL order CI4 applies them.
// CI4 MigrationRunner sorts by uid = digits(version) . class, i.e. the numeric
// timestamp prefix. Sorting the basenames reproduces that order well enough for
// static column reconstruction (a column is defined once and never re-added).
// ---------------------------------------------------------------------------
$migFiles = glob($root . '/app/Modules/*/Database/Migrations/*.php') ?: [];
usort($migFiles, static fn ($a, $b) => strcmp(basename($a), basename($b)));

chk('discovered migration files (>50)', count($migFiles) > 50);

// ===========================================================================
// PART A — cache-bust guard on every guarded migration
// ===========================================================================
echo "\nA. resetDataCache() guard on schema-sniffing migrations\n";

/** Return the body of a `public function <name>(): void { … }` block, or null. */
function methodBody(string $src, string $name): ?string
{
    if (! preg_match('/public function ' . preg_quote($name, '/') . '\(\)\s*:\s*void\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]) - 1; // position of the opening brace
    $depth = 0;
    $len   = strlen($src);
    for ($i = $start; $i < $len; $i++) {
        $c = $src[$i];
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start + 1, $i - $start - 1);
            }
        }
    }

    return null;
}

$guardedCount = 0;
foreach ($migFiles as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }
    // Only migrations that BRANCH on schema state are vulnerable.
    if (! preg_match('/->(tableExists|fieldExists)\s*\(/', $src)) {
        continue;
    }
    $guardedCount++;
    $base = basename($file);

    foreach (['up', 'down'] as $method) {
        $body = methodBody($src, $method);
        if ($body === null) {
            // down() is occasionally omitted; only require the guard where the
            // method exists AND itself sniffs schema.
            continue;
        }
        if (! preg_match('/->(tableExists|fieldExists)\s*\(/', $body)) {
            continue; // this method doesn't sniff, no bust needed
        }
        chk("{$base}::{$method}() busts schema cache", str_contains($body, 'resetDataCache('));
    }
}
chk('found the guarded-migration cohort (>=15)', $guardedCount >= 15);

// ===========================================================================
// PART B — static schema reconstruction + runtime reference check
// ===========================================================================
echo "\nB. reconstructed schema covers runtime column references\n";

/**
 * Very small SQL-shape scanner over the migration corpus. It does NOT execute
 * anything; it reads the literal SQL strings the migrations pass to query() and
 * accumulates, per table, the columns that CREATE TABLE and ADD COLUMN declare.
 * DROP COLUMN removes them again so refresh-style down()s don't leave phantoms.
 */
$tables = []; // table => set(column => true)

$normalize = static fn (string $s): string => strtolower(trim($s, " \t\n\r`\""));

foreach ($migFiles as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }

    // ---- CREATE TABLE [IF NOT EXISTS] <name> ( <body> ) --------------------
    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-z0-9_]+)[`"]?\s*\((.*?)\)\s*ENGINE/is',
        $src,
        $creates,
        PREG_SET_ORDER,
    )) {
        foreach ($creates as $c) {
            $table = $normalize($c[1]);
            $tables[$table] ??= [];
            foreach (preg_split('/\r?\n/', $c[2]) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // Skip constraint / key lines.
                if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY)\b/i', $line)) {
                    continue;
                }
                if (preg_match('/^[`"]?([a-z0-9_]+)[`"]?\s+/i', $line, $col)) {
                    $tables[$table][$normalize($col[1])] = true;
                }
            }
        }
    }

    // ---- ALTER TABLE <literal-name> … ADD COLUMN <col> … ------------------
    // Direct ALTERs where the table is written literally in the SQL string.
    if (preg_match_all(
        '/ALTER\s+TABLE\s+[`"]?([a-z0-9_]+)[`"]?(.*?)(?=;|\'\s*\)|\'\s*,|$)/is',
        $src,
        $alters,
        PREG_SET_ORDER,
    )) {
        foreach ($alters as $a) {
            $table = $normalize($a[1]);
            $tables[$table] ??= [];
            if (preg_match_all('/ADD\s+COLUMN\s+[`"]?([a-z0-9_]+)[`"]?/i', $a[2], $adds)) {
                foreach ($adds[1] as $col) {
                    $tables[$table][$normalize($col)] = true;
                }
            }
            // DROP COLUMN in a down() is expected; we only assert the up() path
            // declares the column, so the reconstructed set is the UNION of all
            // CREATE/ADD across the corpus and DROPs are intentionally ignored.
        }
    }

    // ---- ALTER TABLE {$var} … ADD COLUMN <col> … (loop over a const array) --
    // Several migrations add the same columns to every table in a `const` list
    // via `foreach (self::TABLES as $table) { "ALTER TABLE {$table} ADD COLUMN …" }`.
    // The table name is interpolated, so resolve it against the quoted strings
    // declared in that file's `const` array(s) and fan the columns out to each.
    // This is exactly the shape that shipped the stage_code / scope_mode fatals.
    if (preg_match_all(
        '/ALTER\s+TABLE\s+\{\$[a-z0-9_]+\}(.*?)["\']\s*(?:\.|,|\))/is',
        $src,
        $loopAlters,
        PREG_SET_ORDER,
    )) {
        // Candidate table names = every quoted string appearing in a `const … = [ … ]`
        // declaration in this file (covers both list values and associative keys).
        $candidates = [];
        if (preg_match_all('/const\s+[A-Z0-9_]+\s*=\s*\[(.*?)\]\s*;/is', $src, $constBlocks)) {
            foreach ($constBlocks[1] as $block) {
                if (preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/i', $block, $qs)) {
                    foreach ($qs[1] as $name) {
                        $candidates[$normalize($name)] = true;
                    }
                }
            }
        }

        $loopColumns = [];
        foreach ($loopAlters as $la) {
            if (preg_match_all('/ADD\s+COLUMN\s+[`"]?([a-z0-9_]+)[`"]?/i', $la[1], $adds)) {
                foreach ($adds[1] as $col) {
                    $loopColumns[$normalize($col)] = true;
                }
            }
        }

        foreach (array_keys($candidates) as $table) {
            $tables[$table] ??= [];
            foreach (array_keys($loopColumns) as $col) {
                $tables[$table][$col] = true;
            }
        }
    }
}

chk('reconstructed activity_categories table', isset($tables['activity_categories']));
chk('reconstructed role_assignments table', isset($tables['role_assignments']));

/**
 * Runtime column references that MUST exist. Sourced from the shipped fatals and
 * the sibling columns produced by the same guarded-ALTER migrations, so any
 * future skip of those ADD COLUMNs re-fails here without a database.
 */
$required = [
    // The two shipped fatals (log 2026-09-16):
    'activity_categories' => ['stage_code', 'phase', 'code', 'organization_id'],
    'role_assignments'    => ['scope_mode', 'include_crosscut', 'include_descendants', 'scope_group_id', 'status'],
    // Sibling grant tables that receive scope_mode / include_crosscut via the
    // same guarded loops (000054 / 000056):
    'access_requests'      => ['scope_mode', 'include_crosscut'],
    'delegations'          => ['scope_mode', 'include_crosscut'],
    'break_glass_sessions' => ['scope_mode', 'include_crosscut'],
    // stage_code also lands on these two via 000060:
    'gamification_rules'   => ['stage_code', 'phase'],
    'follow_up_types'      => ['stage_code'],
    // Administrative-configuration surfaces written by AdminConfigSeeder — assert
    // the columns that seeder/service code inserts actually exist.
    'platform_settings'        => ['setting_key', 'value_json', 'version'],
    'feature_flags'            => ['flag_key', 'group_id', 'enabled'],
    'gamification_config'       => ['config_key', 'config_value', 'config_type'],
    'group_configurations'      => ['capability', 'inheritance_mode', 'value_json'],
    'payment_provider_configs'  => ['provider', 'currencies', 'methods', 'status'],
    'stream_giving_configs'     => ['stream_id', 'enabled', 'suggested_amounts', 'currency'],
    'grant_scope_groups'        => ['grant_type', 'grant_id', 'group_id'],
];

foreach ($required as $table => $cols) {
    $have = $tables[$table] ?? [];
    foreach ($cols as $col) {
        chk("{$table}.{$col} is defined by some migration", isset($have[$col]));
    }
}

// ---------------------------------------------------------------------------
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
