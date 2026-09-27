<?php

declare(strict_types=1);

namespace WBS\Shared\I18n\Providers;

use WBS\Shared\I18n\TranslationProvider;

/**
 * DB-backed source: notification_templates.
 *
 * Surfaces the ACTIVE template subject/body for each logical key as flat catalog
 * entries so DB-authored, per-org notification copy merges into the same
 * frontend catalog as bundled strings:
 *
 *   "notif.<key_name>.<channel>.subject" => subject
 *   "notif.<key_name>.<channel>.body"    => body
 *
 * Locale/version/fallback are handled by the registry (English merged under the
 * target locale), but the SQL should still prefer the highest active version per
 * (key,channel) for the requested locale.
 *
 * Resource discipline — the whole point of this task:
 *  - The registry calls load($locale) exactly ONCE per locale per cache rebuild,
 *    so this is ONE bounded query per locale, never one per template/string.
 *  - version() must be O(1): a MAX(updated_at)/MAX(version) style stamp or an
 *    INCR counter passed in as $versionFn — NEVER a full table scan.
 *  - Both the row loader and the version stamp are INJECTED, so this class does
 *    no framework/DB calls itself and stays unit-testable; the composition root
 *    wires the actual queries.
 */
final class NotificationTemplateProvider implements TranslationProvider
{
    /** @var callable(string):array<int,array<string,mixed>> rows for a locale */
    private $rowsFn;

    /** @var callable():string O(1) version stamp */
    private $versionFn;

    private string $keyPrefix;

    /**
     * @param callable(string):array<int,array{key_name:string,channel:string,subject?:?string,body?:?string}> $rowsFn
     * @param callable():string $versionFn O(1) change stamp (max updated_at / counter)
     */
    public function __construct(callable $rowsFn, callable $versionFn, string $keyPrefix = 'notif')
    {
        $this->rowsFn    = $rowsFn;
        $this->versionFn = $versionFn;
        $this->keyPrefix = trim($keyPrefix, '.');
    }

    public function name(): string
    {
        return 'notification_templates';
    }

    public function load(string $locale): array
    {
        $out = [];
        foreach (($this->rowsFn)($locale) as $row) {
            $keyName = (string) ($row['key_name'] ?? '');
            $channel = (string) ($row['channel'] ?? '');
            if ($keyName === '' || $channel === '') {
                continue;
            }
            $base = $this->keyPrefix . '.' . $keyName . '.' . $channel;
            if (isset($row['subject']) && $row['subject'] !== null && $row['subject'] !== '') {
                $out[$base . '.subject'] = (string) $row['subject'];
            }
            if (isset($row['body']) && $row['body'] !== null && $row['body'] !== '') {
                $out[$base . '.body'] = (string) $row['body'];
            }
        }

        return $out;
    }

    public function version(): string
    {
        return 'n' . ($this->versionFn)();
    }
}
