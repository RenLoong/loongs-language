<?php

declare(strict_types=1);

use Loongs\Language\Lang;

if (!function_exists('__')) {
    /**
     * Translates $key with the default translator (Lang::setTranslator()) in the current locale.
     *
     * @param array<string, mixed> $params
     */
    function __(string $key, array $params = [], ?string $locale = null): string
    {
        return Lang::get($key, $params, $locale);
    }
}

if (!function_exists('trans_choice')) {
    /** @param array<string, mixed> $params */
    function trans_choice(string $key, int|float $count, array $params = [], ?string $locale = null): string
    {
        return Lang::choice($key, $count, $params, $locale);
    }
}
