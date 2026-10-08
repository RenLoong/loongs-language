<?php

declare(strict_types=1);

namespace Loongs\Language\Contract;

/** What application code needs from a translator (Translator implements it; easy to fake in tests). */
interface TranslatorInterface
{
    /** Current locale (per coroutine / request), always one of the supported locales. */
    public function locale(): string;

    /** @param array<string, mixed> $params {name} placeholders */
    public function get(string $key, array $params = [], ?string $locale = null): string;

    /** @param array<string, mixed> $params {name} placeholders; {count} is set to $count */
    public function choice(string $key, int|float $count, array $params = [], ?string $locale = null): string;

    public function has(string $key, ?string $locale = null): bool;
}
