<?php

declare(strict_types=1);

namespace Loongs\Language;

use Loongs\Language\Contract\TranslatorInterface;

/**
 * Translator over a Catalog.
 *
 *   $t = new Translator(Catalog::fromDirectories(__DIR__ . '/lang', $app . '/lang'), fallback: 'en-US');
 *   LocaleContext::set($t->negotiate($request->header('accept-language')));
 *   $t->get('已删除 {n} 条', ['n' => 3]);              // en-US pack: "Deleted {n} items" → "Deleted 3 items"
 *   $t->choice('{count} item|{count} items', 2);
 *
 * Lookup for locale L walks Locale::chain(L, fallback) — e.g. fr-FR → fr → en-US (the default fallback). With $sourceKeys
 * (default) keys are texts in the fallback language ("gettext style"): a key missing from every pack
 * is returned as is and the fallback locale needs no pack at all. Keys looked up without a
 * translation in L (before reaching the fallback) are recorded in $missing.
 */
final class Translator implements TranslatorInterface
{
    public readonly string $fallback;

    /** @var list<string>|null */
    private ?array $supported;

    /**
     * @param list<string>|null $supported locales offered to clients (default: fallback + every locale with a pack)
     */
    public function __construct(
        public readonly Catalog $catalog = new Catalog(),
        string $fallback = 'en-US',
        ?array $supported = null,
        public readonly bool $sourceKeys = true,
        public readonly MissingTranslations $missing = new MissingTranslations(),
    ) {
        $this->fallback = Locale::normalize($fallback) ?? throw new \InvalidArgumentException("invalid fallback locale \"{$fallback}\"");
        $this->supported = $supported === null ? null : self::uniqueTags([$this->fallback, ...$supported]);
    }

    /** @return list<string> fallback first */
    public function supported(): array
    {
        return $this->supported ??= self::uniqueTags([$this->fallback, ...$this->catalog->locales()]);
    }

    public function isSupported(string $locale): bool
    {
        return in_array(Locale::normalize($locale), $this->supported(), true);
    }

    /** Best supported locale for an Accept-Language header / a tag; the fallback when nothing matches. */
    public function negotiate(?string $acceptLanguage): string
    {
        return Locale::negotiate($acceptLanguage, $this->supported(), $this->fallback);
    }

    public function locale(): string
    {
        $l = LocaleContext::get();

        return $l === null ? $this->fallback : $this->negotiate($l);
    }

    /**
     * The message for $key in $locale (current locale when null) following the fallback chain, or null
     * when the key itself is the text to show (source key without a pack entry).
     */
    public function lookup(string $key, ?string $locale = null): ?string
    {
        $locale = $locale === null ? $this->locale() : (Locale::normalize($locale) ?? $this->fallback);
        [$own, $fallbackChain] = $this->split($locale);
        foreach ($own as $l) {
            $m = $this->catalog->get($l, $key);
            if ($m !== null) {
                return $m;
            }
        }
        if ($own !== [] || !$this->sourceKeys) {
            $found = $this->fromChain($fallbackChain, $key);
            if ($own !== [] || $found === null) {
                $this->missing->record($locale, $key);
            }

            return $found;
        }

        return $this->fromChain($fallbackChain, $key);
    }

    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        return MessageFormatter::format($this->lookup($key, $locale) ?? $key, $params);
    }

    public function choice(string $key, int|float $count, array $params = [], ?string $locale = null): string
    {
        $message = MessageFormatter::choose($this->lookup($key, $locale) ?? $key, $count);

        return MessageFormatter::format($message, ['count' => $count, ...$params]);
    }

    /** true when $locale (current when null) has its own translation (for the fallback locale with source keys: always). */
    public function has(string $key, ?string $locale = null): bool
    {
        $locale = $locale === null ? $this->locale() : (Locale::normalize($locale) ?? $this->fallback);
        [$own, $fallbackChain] = $this->split($locale);
        if ($own === []) {
            return $this->sourceKeys || $this->fromChain($fallbackChain, $key) !== null;
        }
        foreach ($own as $l) {
            if ($this->catalog->has($l, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys without a translation in $locale (nothing is recorded) — for checks such as "every menu
     * title / page label has an en-US text".
     *
     * @param iterable<string> $keys
     * @return list<string> unique, in input order
     */
    public function missingKeys(iterable $keys, string $locale): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (!isset($out[$k]) && !$this->has($k, $locale)) {
                $out[$k] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * Keys present in the $locale pack but not in $reference (e.g. stale entries left after a source text changed).
     *
     * @param list<string> $known all keys the application uses
     * @return list<string>
     */
    public function unusedKeys(string $locale, array $known): array
    {
        $known = array_flip($known);

        return array_values(array_filter(array_map('strval', array_keys($this->catalog->all($locale))), static fn (string $k): bool => !isset($known[$k])));
    }

    /** @return array{0: list<string>, 1: list<string>} locale-own part of the chain, fallback part */
    private function split(string $locale): array
    {
        $fallbackChain = Locale::chain($this->fallback);
        $own = array_values(array_diff(Locale::chain($locale), $fallbackChain));

        return [$own, $fallbackChain];
    }

    /** @param list<string> $chain */
    private function fromChain(array $chain, string $key): ?string
    {
        foreach ($chain as $l) {
            $m = $this->catalog->get($l, $key);
            if ($m !== null) {
                return $m;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private static function uniqueTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            $n = Locale::normalize($t);
            if ($n !== null && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }
}
