<?php

declare(strict_types=1);

namespace Loongs\Language;

use Closure;

/**
 * Records keys looked up without a translation in the requested locale (diagnostics only; bounded,
 * so a long-running worker cannot grow it forever). Use $onMissing to log them as they happen.
 */
final class MissingTranslations implements \Countable
{
    /** @var array<string, array<string, true>> locale => key => true */
    private array $keys = [];
    private int $count = 0;

    /** @param (Closure(string $locale, string $key): void)|null $onMissing called once per new locale+key */
    public function __construct(
        public readonly int $limit = 2000,
        private readonly ?Closure $onMissing = null,
    ) {
    }

    public function record(string $locale, string $key): void
    {
        if (isset($this->keys[$locale][$key]) || $this->count >= $this->limit) {
            return;
        }
        $this->keys[$locale][$key] = true;
        $this->count++;
        if ($this->onMissing !== null) {
            ($this->onMissing)($locale, $key);
        }
    }

    /** @return array<string, list<string>> locale => keys (sorted) */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys as $locale => $keys) {
            $k = array_map('strval', array_keys($keys));
            sort($k);
            $out[$locale] = $k;
        }
        ksort($out);

        return $out;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function clear(): void
    {
        $this->keys = [];
        $this->count = 0;
    }
}
