<?php

declare(strict_types=1);

namespace Loongs\Language;

/**
 * BCP 47 helpers: normalisation ("en_us" → "en-US", "zh-hans-cn" → "zh-Hans-CN"), fallback chains
 * ("en-US" → en-US, en, <fallback>) and Accept-Language negotiation (RFC 9110 §12.5.4 q-values).
 */
final class Locale
{
    /** Normalised tag, or null when $tag is not a plausible language tag. */
    public static function normalize(?string $tag): ?string
    {
        $tag = trim((string) $tag);
        if ($tag === '' || preg_match('/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $tag) !== 1) {
            return null;
        }
        $parts = preg_split('/[-_]/', $tag);
        $out = [strtolower(array_shift($parts))];
        foreach ($parts as $i => $p) {
            $out[] = match (true) {
                strlen($p) === 4 && ctype_alpha($p) && $i === 0 => ucfirst(strtolower($p)),   // script: Hans
                strlen($p) === 2 && ctype_alpha($p), strlen($p) === 3 && ctype_digit($p) => strtoupper($p),   // region: CN / 419
                default => strtolower($p),
            };
        }

        return implode('-', $out);
    }

    /** Primary language subtag ("en-US" → "en"). */
    public static function language(string $tag): string
    {
        return strtolower(explode('-', str_replace('_', '-', $tag), 2)[0]);
    }

    /**
     * Lookup chain: the tag, its truncations (zh-Hans-CN → zh-Hans → zh), then $fallback (and its own
     * truncations). Duplicates removed, order kept.
     *
     * @return list<string>
     */
    public static function chain(string $locale, ?string $fallback = null): array
    {
        $chain = [];
        foreach ([$locale, $fallback] as $tag) {
            $tag = self::normalize($tag);
            if ($tag === null) {
                continue;
            }
            $parts = explode('-', $tag);
            for ($n = count($parts); $n > 0; $n--) {
                $chain[] = implode('-', array_slice($parts, 0, $n));
            }
        }

        return array_values(array_unique($chain));
    }

    /**
     * Parses Accept-Language into [tag => q] sorted by q (desc, stable); q=0 and junk dropped.
     *
     * @return array<string, float>
     */
    public static function parseAcceptLanguage(?string $header): array
    {
        $items = [];
        foreach (explode(',', (string) $header) as $pos => $part) {
            $bits = array_map('trim', explode(';', $part));
            $tag = $bits[0] === '*' ? '*' : self::normalize($bits[0]);
            if ($tag === null) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^q\s*=\s*([01](?:\.\d{0,3})?)$/i', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0 && !isset($items[$tag])) {
                $items[$tag] = [$q, $pos];
            }
        }
        uasort($items, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(static fn (array $v): float => $v[0], $items);
    }

    /**
     * Best supported locale for an Accept-Language header (or a single tag). Per requested tag, in q
     * order: exact match, then a supported tag that the request truncates to ("en-GB" → "en"), then
     * the first supported tag with the same primary language ("en" → "en-US"). "*" → $default.
     *
     * @param list<string> $supported
     */
    public static function negotiate(?string $header, array $supported, string $default): string
    {
        $norm = [];
        foreach ($supported as $s) {
            $n = self::normalize($s);
            if ($n !== null) {
                $norm[strtolower($n)] = $n;
            }
        }
        foreach (array_keys(self::parseAcceptLanguage($header)) as $tag) {
            if ($tag === '*') {
                return $default;
            }
            foreach (self::chain($tag) as $candidate) {
                if (isset($norm[strtolower($candidate)])) {
                    return $norm[strtolower($candidate)];
                }
            }
            $lang = self::language($tag);
            foreach ($norm as $n) {
                if (self::language($n) === $lang) {
                    return $n;
                }
            }
        }

        return self::normalize($default) ?? $default;
    }
}
