<?php

declare(strict_types=1);

namespace Loongs\Language;

/**
 * Process-wide default translator for helpers (__(), trans_choice()) and code without DI. Set it
 * once at boot; the translator itself is read-only, the current locale is per coroutine
 * (LocaleContext), so sharing it between requests is safe.
 */
final class Lang
{
    private static ?Translator $translator = null;

    public static function setTranslator(?Translator $translator): void
    {
        self::$translator = $translator;
    }

    /** The default translator (an empty zh-CN one until setTranslator() is called). */
    public static function translator(): Translator
    {
        return self::$translator ??= new Translator();
    }

    public static function locale(): string
    {
        return self::translator()->locale();
    }

    /** @param array<string, mixed> $params */
    public static function get(string $key, array $params = [], ?string $locale = null): string
    {
        return self::translator()->get($key, $params, $locale);
    }

    /** @param array<string, mixed> $params */
    public static function choice(string $key, int|float $count, array $params = [], ?string $locale = null): string
    {
        return self::translator()->choice($key, $count, $params, $locale);
    }
}
