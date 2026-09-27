<?php

declare(strict_types=1);

/**
 * Shared framework stubs + a locale-aware renderer for the SELF-CONTAINED Events
 * form/launcher view tests (checkin, expenses, and the create form). Lets those
 * tests render a view outside a booted CodeIgniter app: esc(), base_url(),
 * service()/config() (feeding _locale.php), and a lang() that reads the real
 * Events language files for the locale under test.
 */

if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            public function getLocale()
            {
                return $GLOBALS['__evLoc'] ?? 'en';
            }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('base_url')) {
    function base_url($p = '')
    {
        return 'https://public.test/' . ltrim((string) $p, '/');
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') {
            return $key;
        }
        $v = $GLOBALS['__evLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}

/**
 * Build a renderer bound to the Events language dir. Returns a closure
 * (file, data, locale) => rendered HTML.
 */
function wbs_events_renderer(string $langDir): callable
{
    return static function (string $file, array $data, string $loc) use ($langDir): string {
        $GLOBALS['__evLoc']  = $loc;
        $GLOBALS['__evLang'] = require $langDir . "/$loc/Events.php";
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    };
}
