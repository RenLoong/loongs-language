<?php

declare(strict_types=1);

namespace Loongs\Language;

use Swoole\Coroutine;

/**
 * The current locale of this request. Inside a Swoole coroutine it lives in the coroutine context
 * (gone with the coroutine, never shared between requests); child coroutines started with go() see
 * the parent's locale unless they set their own. Outside coroutines (CLI, FPM, tests) a process-local
 * slot is used.
 */
final class LocaleContext
{
    private const KEY = 'loongs.language.locale';

    private static ?string $local = null;

    public static function set(?string $locale): void
    {
        $locale = $locale === null ? null : (Locale::normalize($locale) ?? null);
        if (self::cid() > 0) {
            Coroutine::getContext()[self::KEY] = $locale;
        } else {
            self::$local = $locale;
        }
    }

    public static function get(): ?string
    {
        $cid = self::cid();
        if ($cid <= 0) {
            return self::$local;
        }
        for ($depth = 0; $cid > 0 && $depth < 32; $depth++) {
            $ctx = Coroutine::getContext($cid);
            if ($ctx === null) {
                break;
            }
            if (isset($ctx[self::KEY])) {
                return $ctx[self::KEY];
            }
            $cid = Coroutine::getPcid($cid);
            if (!is_int($cid)) {
                break;
            }
        }

        return null;
    }

    /**
     * Runs $fn with $locale as the current locale and restores the previous one afterwards.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(string $locale, callable $fn): mixed
    {
        $cid = self::cid();
        $prev = $cid > 0 ? (Coroutine::getContext()[self::KEY] ?? null) : self::$local;
        self::set($locale);
        try {
            return $fn();
        } finally {
            self::set($prev);
        }
    }

    private static function cid(): int
    {
        return extension_loaded('swoole') ? Coroutine::getCid() : -1;
    }
}
