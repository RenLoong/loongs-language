# loongs/language

Translations for loong-swoole apps (PHP 8.4, Swoole-safe):

- **Translator** over language packs — php / json files from several directories, later directories
  override earlier ones (framework → package → app packs), nested keys, plural messages;
- **Accept-Language negotiation** with q-values and primary-language matching, and **fallback chains**
  (default fallback `en-US`: `fr-FR` → `fr` → `en-US` → the key itself);
- **{name} placeholders** and simple **pluralization** (`one|other`, `{0} …|{1} …|[2,*] …`);
- **missing-key reporting** (bounded runtime recorder + `missingKeys()` for CI checks);
- **per-coroutine current locale** (`LocaleContext`): each request coroutine has its own locale, child
  coroutines inherit it, nothing leaks between requests;
- optional integrations: a **loongs/framework middleware** and a **loongs/render adapter** (both are
  `suggest`, not hard dependencies).

```
composer require loongs/language
```

## Language packs

The directory name **is** the locale id. Each locale directory holds as many php / json files as you
want; they are merged.

```
apps/Admin/lang/
  en-US/                 locale id
    menu.php             <?php return ['系统管理' => 'System'];
    pages.php
    errors.php           <?php return ['已删除 {n} 条' => 'Deleted {n} items'];
    enums.php
  zh-CN/
    menu.php
    errors.php
  en.json                also accepted: a single <locale>.php / <locale>.json file
```

- **Merge order** (later overrides earlier): across directories, the order passed to
  `Catalog::fromDirectories()`; inside one directory, `<locale>.php`, then `<locale>.json`, then every
  file in `<locale>/` whose name ends in `.php` or `.json`, **sorted by filename** (`a.php` before
  `b.php` before `m.json`). Names are matched case-insensitively (`en_us/menu.php` is `en-US`).
  Nested subdirectories under a locale are not read. Missing directories are ignored.
- Nested arrays become dotted keys (`auth.failed`); a list is a plural message (`['{count} item', '{count} items']`).
- Keys are usually **source texts** ("gettext style": `__('保存')`). The **default fallback locale is
  `en-US`**: a request with no `Accept-Language` (and no current locale) is English, and a key missing
  from every pack is returned unchanged. Give `zh-CN` its own files when the keys are not already the
  Chinese text you want to show. Symbolic keys (`auth.failed`) work too (`sourceKeys: false` reports
  them as missing in the fallback locale as well).
- **Placeholders.** `__($key, ['name' => $n])`, `Lang::get()` and `$translator->get()` replace `{name}`
  in the message (the same syntax; there is no separate `trans()`). `null` becomes an empty string.
  Plurals: `trans_choice($key, $count, $params)`.
- Directories are read lazily once per locale and process; a `Catalog` is read-only afterwards and safe
  to share between coroutines (`reload()` in development).

```php
use Loongs\Language\{Catalog, Lang, LocaleContext, Translator};

$translator = new Translator(
    Catalog::fromDirectories(__DIR__ . '/lang', ...glob($appsDir . '/*/lang', GLOB_ONLYDIR)),
    // fallback defaults to 'en-US'
    supported: ['zh-CN'],              // optional: default = fallback + every locale with a pack
);
Lang::setTranslator($translator);       // for __() / trans_choice() / Lang::get()

LocaleContext::set($translator->negotiate(null));                 // no Accept-Language → en-US
__('保存');                                                        // Save (from en-US/menu.php)
__('已删除 {n} 条', ['n' => 3]);                                   // Deleted 3 items
$translator->get('welcome', ['name' => 'Ada']);                    // "Hello, {name}" → "Hello, Ada"
trans_choice('{count} 条消息', 2);                                  // 2 messages
```

## Locale negotiation

`Locale::negotiate($acceptLanguage, $supported, $default)` / `$translator->negotiate()`: tags in q
order (q=0 dropped, `*` = default); per tag an exact match, then a supported truncation (`en-AU` →
`en`), then the first supported tag with the same primary language (`en` → `en-US`, `zh-TW` →
`zh-CN`). `Locale::normalize()` canonicalises tags (`en_us` → `en-US`, `zh-hans-cn` → `zh-Hans-CN`),
`Locale::chain('zh-CN', 'en-US')` gives `['zh-CN', 'zh', 'en-US', 'en']`. `new Translator()` falls back to `en-US`.

## Current locale (Swoole-safe)

`LocaleContext::set() / get() / run($locale, $fn)`: inside a coroutine the locale lives in
`Swoole\Coroutine::getContext()` and dies with the request coroutine; `go()` children see the parent's
locale. Outside coroutines (CLI, tests, FPM) a process-local slot is used. `Translator::locale()`
returns the current locale negotiated against the supported list (fallback when unset).

## Missing translations

- `$translator->missingKeys($keys, 'en-US')` — keys without an `en-US`/`en` text (nothing recorded);
  use it in a console / CI check over menu titles, page texts (`loongs/render` `PageRegistry::inspect()['texts']`), etc.
- `$translator->missing` — keys looked up at runtime without a translation (bounded, default 2000
  entries; `new MissingTranslations(onMissing: fn ($locale, $key) => error_log(...))` to log them).
- `$translator->unusedKeys('en-US', $knownKeys)` — stale pack entries.

## loongs/framework middleware

```php
// apps/Admin/middleware.php  (Translator bound in the container)
return [\Loongs\Language\Integration\Framework\LocaleMiddleware::class, ...];
```

Locale from the first supported value of the `lang` / `ui_locales` (OpenID Connect) query or form
parameter, else `Accept-Language`, else the fallback. Sets `LocaleContext` for the rest of the
pipeline (restored afterwards), the request attribute `locale`, and adds `Content-Language` and
`Vary: Accept-Language` to the response.

## loongs/render adapter

```php
$registry = new PageRegistry($factory, translator: new \Loongs\Language\Integration\Render\RenderTranslator($translator));
$client = ClientInfo::fromHeaders($headers)->withLocale($translator->locale());
```

Page descriptions are translated per client locale (titles, labels, options, messages, chart names …);
the locale is part of the render cache key and ETag.

## Tests

```
composer test          # php tests/language_test.php cli && php tests/language_test.php co
```

Plain PHP, no PHPUnit. `co` runs everything inside `Swoole\Coroutine\run` plus coroutine isolation and
middleware tests (needs ext-swoole; the middleware test needs loongs/framework installed or a sibling
`../framework` checkout; the render adapter test a sibling `../render`).

## License

MIT
