<?php

declare(strict_types=1);

namespace Loongs\Language;

/**
 * {name} placeholders and simple pluralization.
 *
 * Plural messages are "|"-separated forms:
 *   "one item|{count} items"                 → 2 forms: count == 1 → first, else second
 *   "{0} no items|{1} one item|[2,*] {count} items"   → explicit values / ranges ([min,max], * = open)
 *   "{count} 条"                             → one form (languages without plural forms)
 * A list in a language pack (['one item', '{count} items']) is the same as the "|" form.
 */
final class MessageFormatter
{
    /** @param array<string, mixed> $params */
    public static function format(string $message, array $params = []): string
    {
        if ($params === [] || !str_contains($message, '{')) {
            return $message;
        }
        $map = [];
        foreach ($params as $k => $v) {
            $map['{' . $k . '}'] = self::stringify($v);
        }

        return strtr($message, $map);
    }

    public static function choose(string $message, int|float $count): string
    {
        $forms = self::split($message);
        if (count($forms) === 1) {
            return self::strip($forms[0]);
        }
        foreach ($forms as $form) {
            if (preg_match('/^\{(-?\d+(?:\.\d+)?)\}\s?(.*)$/s', $form, $m) === 1) {
                if ((float) $m[1] == $count) {
                    return $m[2];
                }
            } elseif (preg_match('/^\[(-?\d+(?:\.\d+)?|\*)\s*,\s*(-?\d+(?:\.\d+)?|\*)\]\s?(.*)$/s', $form, $m) === 1) {
                if (($m[1] === '*' || $count >= (float) $m[1]) && ($m[2] === '*' || $count <= (float) $m[2])) {
                    return $m[3];
                }
            }
        }
        $plain = array_values(array_filter($forms, static fn (string $f): bool => preg_match('/^(\{-?[\d.]+\}|\[[^\]]*\])/', $f) !== 1));
        if ($plain === []) {
            return self::strip(end($forms));
        }

        return $count == 1 || count($plain) === 1 ? $plain[0] : $plain[1];
    }

    /** @return list<string> "|" split, "\|" keeps a literal pipe */
    private static function split(string $message): array
    {
        $parts = preg_split('/(?<!\\\\)\|/', $message);

        return array_map(static fn (string $p): string => str_replace('\|', '|', $p), $parts);
    }

    private static function strip(string $form): string
    {
        return preg_replace('/^(\{-?[\d.]+\}|\[[^\]]*\])\s?/', '', $form) ?? $form;
    }

    private static function stringify(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_scalar($v), $v instanceof \Stringable => (string) $v,
            $v instanceof \BackedEnum => (string) $v->value,
            default => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
