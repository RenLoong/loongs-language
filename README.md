# loongs/language

Translations for loong-swoole apps (PHP 8.4, Swoole-safe):

- **Translator** over language packs — php / json files from several directories, later directories
  override earlier ones (framework → package → app packs), nested keys, plural messages;
- **Accept-Language negotiation** with q-values and primary-language matching, and **fallback chains**
  (`en-US` → `en` → `zh-CN` → the key itself);
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

```
apps/Admin/lang/
  zh-CN.php          <?php return ['auth' => ['failed' => '用户名或密码错误']];
  en-US.php          <?php return ['保存' => 'Save', '已删除 {n} 条' => 'Deleted {n} items', 'auth' => ['failed' => 'Wrong username or password']];
  en-US/menu.json    {"系统管理": "System"}
  en.json            {"取消": "Cancel"}
```

- `<dir>/<locale>.php` (returns an array), `<dir>/<locale>.json`, `<dir>/<locale>/*.php|json`; file names
  are matched case-insensitively (`en_us.php` works); missing directories are ignored.
- Nested arrays become dotted keys (`auth.failed`); a list is a plural message (`['{count} item', '{count} items']`).
- Keys are usually **source-language texts** ("gettext style": `__('保存')`), so the source locale needs
  no pack and untranslated texts show the source. Symbolic keys (`auth.failed`) work too; put them in
  the source pack as well (or use `sourceKeys: false` to report them as missing there).
- Directories are read lazily once per locale and process; a `Catalog` is read-only afterwards and safe
  to share between coroutines (`reload()` in development).

```php
use Loongs\Language\{Catalog, Lang, LocaleContext, Translator};

$translator = new Translator(
    Catalog::fromDirectories(__DIR__ . '/lang', ...glob($appsDir . '/*/lang', GLOB_ONLYDIR)),
    fallback: 'zh-CN',                 // source / fallback locale
    supported: ['en-US'],              // optional: default = fallback + every locale with a pack
);
Lang::setTranslator($translator);       // for __() / trans_choice() / Lang::get()

LocaleContext::set($translator->negotiate('en-GB,en;q=0.9,zh;q=0.5'));   // → en-US
__('保存');                                   // Save
__('已删除 {n} 条', ['n' => 3]);              // Deleted 3 items
trans_choice('{count} 条消息', 2);             // 2 messages
$translator->get('auth.failed', locale: 'fr'); // 用户名或密码错误 (fallback)
```

## Locale negotiation

`Locale::negotiate($acceptLanguage, $supported, $default)` / `$translator->negotiate()`: tags in q
order (q=0 dropped, `*` = default); per tag an exact match, then a supported truncation (`en-AU` →
`en`), then the first supported tag with the same primary language (`en` → `en-US`, `zh-TW` →
`zh-CN`). `Locale::normalize()` canonicalises tags (`en_us` → `en-US`, `zh-hans-cn` → `zh-Hans-CN`),
`Locale::chain('en-US', 'zh-CN')` gives `['en-US', 'en', 'zh-CN', 'zh']`.

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
