<?php

declare(strict_types=1);

namespace Loongs\Language;

use Loongs\Language\Exception\InvalidLanguagePack;

/**
 * Language packs: locale → [key => message]. The directory name is the locale id
 * (`lang/en-US/menu.php`, `lang/zh-CN/errors.json`, …). Sources merge in the order added
 * (later overrides earlier):
 *   - arrays via add();
 *   - directories via addDirectory(). Inside one directory, for each locale, files load in this
 *     order (later overrides earlier): `<locale>.php`, then `<locale>.json`, then every
 *     `<locale>/*.(php|json)` sorted by filename (byte order, so `a.php` before `b.php` before
 *     `m.json`). Only that one level is read — nested subdirectories are not. A locale can also
 *     be a single `<locale>.php` / `<locale>.json` file; those load before the directory's files.
 * Nested arrays are flattened with "." keys (['auth' => ['failed' => '…']] → "auth.failed");
 * a list is a plural message (forms joined by "|").
 * Directories are read lazily, once per locale and process (packs are read-only afterwards, so a
 * catalog is safe to share between coroutines).
 */
final class Catalog
{
    /** @var list<array{dir: string}|array{locale: string, messages: array<string, string>}> */
    private array $sources = [];

    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    /** @param array<string, array<mixed>> $messages locale => messages */
    public function __construct(array $messages = [])
    {
        foreach ($messages as $locale => $m) {
            $this->add((string) $locale, $m);
        }
    }

    public static function fromDirectories(string ...$dirs): self
    {
        $c = new self();
        foreach ($dirs as $d) {
            $c->addDirectory($d);
        }

        return $c;
    }

    /** @param array<mixed> $messages */
    public function add(string $locale, array $messages): self
    {
        $locale = self::locale($locale);
        $this->sources[] = ['locale' => $locale, 'messages' => self::flatten($messages, "{$locale} messages")];
        unset($this->loaded[$locale]);

        return $this;
    }

    /** Missing directories are ignored (an app without packs). */
    public function addDirectory(string $dir): self
    {
        $dir = rtrim($dir, '/\\');
        if (is_dir($dir)) {
            $this->sources[] = ['dir' => $dir];
            $this->loaded = [];
        }

        return $this;
    }

    /** @return list<string> every locale with at least one pack (normalised, sorted) */
    public function locales(): array
    {
        $out = [];
        foreach ($this->sources as $s) {
            if (isset($s['locale'])) {
                $out[$s['locale']] = true;
                continue;
            }
            foreach (scandir($s['dir']) ?: [] as $entry) {
                $name = preg_replace('/\.(php|json)$/', '', $entry);
                $tag = Locale::normalize($name);
                if ($entry[0] === '.' || $tag === null || ($name === $entry && !is_dir("{$s['dir']}/{$entry}"))) {
                    continue;
                }
                $out[$tag] = true;
            }
        }
        $out = array_keys($out);
        sort($out);

        return $out;
    }

    public function get(string $locale, string $key): ?string
    {
        return $this->all($locale)[$key] ?? null;
    }

    public function has(string $locale, string $key): bool
    {
        return isset($this->all($locale)[$key]);
    }

    /** @return array<string, string> */
    public function all(string $locale): array
    {
        $locale = self::locale($locale);
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }
        $all = [];
        foreach ($this->sources as $s) {
            if (isset($s['locale'])) {
                if ($s['locale'] === $locale) {
                    $all = array_replace($all, $s['messages']);
                }
                continue;
            }
            foreach (self::files($s['dir'], $locale) as $file) {
                $all = array_replace($all, self::flatten(self::read($file), $file));
            }
        }

        return $this->loaded[$locale] = $all;
    }

    /** Drops what was read from directories (e.g. after editing packs in dev). */
    public function reload(): void
    {
        $this->loaded = [];
    }

    /** @return list<string> */
    private static function files(string $dir, string $locale): array
    {
        $files = [];
        // match the directory entry case-insensitively (en-us.php, en_US.json …)
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry[0] === '.') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            $name = preg_replace('/\.(php|json)$/', '', $entry);
            if (Locale::normalize($name) !== $locale) {
                continue;
            }
            if (is_dir($path)) {
                $sub = array_values(array_filter(scandir($path) ?: [], static fn (string $f): bool => preg_match('/^[^.].*\.(php|json)$/', $f) === 1));
                sort($sub);
                foreach ($sub as $f) {
                    $files[] = "{$path}/{$f}";
                }
            } elseif ($name !== $entry) {
                $files[] = $path;
            }
        }
        // <locale>.php before <locale>.json before <locale>/ (stable, documented order)
        usort($files, static fn (string $a, string $b): int => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        return $files;
    }

    /** @return array<mixed> */
    private static function read(string $file): array
    {
        if (str_ends_with($file, '.json')) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidLanguagePack("{$file}: invalid JSON ({$e->getMessage()})", 0, $e);
            }
        } else {
            $data = (static fn (string $f): mixed => require $f)($file);
        }
        if (!is_array($data)) {
            throw new InvalidLanguagePack("{$file}: a language pack must return / contain an object of messages");
        }

        return $data;
    }

    /**
     * @param array<mixed> $messages
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $where, string $prefix = ''): array
    {
        $out = [];
        foreach ($messages as $k => $v) {
            $key = $prefix . $k;
            if (is_array($v) && $v !== [] && array_is_list($v)) {
                $out[$key] = implode('|', array_map(static fn (mixed $f): string => str_replace('|', '\|', (string) $f), $v));
            } elseif (is_array($v)) {
                $out = array_replace($out, self::flatten($v, $where, $key . '.'));
            } elseif (is_scalar($v) || $v instanceof \Stringable) {
                $out[(string) $key] = (string) $v;
            } elseif ($v !== null) {
                throw new InvalidLanguagePack("{$where}: message \"{$key}\" must be a string");
            }
        }

        return $out;
    }

    private static function locale(string $locale): string
    {
        return Locale::normalize($locale) ?? throw new InvalidLanguagePack("invalid locale \"{$locale}\"");
    }
}
