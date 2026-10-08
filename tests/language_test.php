<?php

declare(strict_types=1);

/**
 * loongs/language tests (plain PHP, no PHPUnit):
 *   php tests/language_test.php cli   — everything that needs no coroutine
 *   php tests/language_test.php co    — the same inside Swoole\Coroutine\run + per-coroutine locale isolation
 *                                       and the loongs/framework middleware (when ../framework is present)
 */

use Loongs\Language\Catalog;
use Loongs\Language\Exception\InvalidLanguagePack;
use Loongs\Language\Integration\Framework\LocaleMiddleware;
use Loongs\Language\Lang;
use Loongs\Language\Locale;
use Loongs\Language\LocaleContext;
use Loongs\Language\MessageFormatter;
use Loongs\Language\MissingTranslations;
use Loongs\Language\Translator;

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$pkg = dirname(__DIR__);
$mode = $argv[1] ?? 'cli';
$psr4 = static function (string $prefix, string $dir, bool $prepend = false): void {
    spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, $prepend);
};
$psr4('Loongs\\Language\\', $pkg . '/src', true);
if (is_file($pkg . '/vendor/autoload.php')) {
    require $pkg . '/vendor/autoload.php';
} else {
    require_once $pkg . '/src/functions.php';
    $psr4('Loongs\\Render\\', dirname($pkg) . '/render/src');   // optional siblings (composer/ checkout)
    $psr4('Loongs\\', dirname($pkg) . '/framework/src');
}

$passed = 0;
$failed = 0;
$tests = [];
function t(string $name, Closure $fn): void
{
    global $tests;
    $tests[] = [$name, $fn];
}
function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg !== '' ? "{$msg}: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
function throws(string $class, Closure $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new RuntimeException("expected {$class}, got " . $e::class . ': ' . $e->getMessage());
        }
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException("message \"{$e->getMessage()}\" lacks \"{$contains}\"");
        }

        return;
    }
    throw new RuntimeException("expected {$class}, nothing thrown");
}

$fx = __DIR__ . '/fixtures';
$make = static fn (): Translator => new Translator(Catalog::fromDirectories("{$fx}/base", "{$fx}/app", "{$fx}/nested", "{$fx}/missing-dir"), 'zh-CN');

// ---------------------------------------------------------------- Locale
t('Locale::normalize', function (): void {
    eq('en-US', Locale::normalize('en_us'));
    eq('zh-Hans-CN', Locale::normalize('ZH-hans-cn'));
    eq('es-419', Locale::normalize('es-419'));
    eq('en', Locale::normalize(' EN '));
    eq(null, Locale::normalize(''));
    eq(null, Locale::normalize('en US'));
    eq(null, Locale::normalize('../etc'));
});
t('Locale::chain with fallback', function (): void {
    eq(['en-US', 'en', 'zh-CN', 'zh'], Locale::chain('en-US', 'zh-CN'));
    eq(['zh-Hans-CN', 'zh-Hans', 'zh'], Locale::chain('zh-Hans-CN', 'zh'));
    eq(['zh-CN', 'zh'], Locale::chain('zh-CN', 'zh-CN'));
});
t('Accept-Language parsing: q-values, order, q=0, junk', function (): void {
    eq(['fr-CH' => 1.0, 'fr' => 0.9, 'en' => 0.8, 'de' => 0.7, '*' => 0.5], Locale::parseAcceptLanguage('fr-CH, fr;q=0.9, en;q=0.8, de;q=0.7, *;q=0.5'));
    eq(['en' => 1.0, 'zh-CN' => 0.5], Locale::parseAcceptLanguage('zh-CN;q=0.5, ja;q=0, en, @@'));
    eq([], Locale::parseAcceptLanguage(null));
});
t('Locale::negotiate', function (): void {
    $sup = ['zh-CN', 'en-US'];
    eq('en-US', Locale::negotiate('en-US,en;q=0.9', $sup, 'zh-CN'));
    eq('en-US', Locale::negotiate('en-GB', $sup, 'zh-CN'), 'same primary language');
    eq('en-US', Locale::negotiate('en', $sup, 'zh-CN'));
    eq('zh-CN', Locale::negotiate('zh-TW,zh;q=0.9', $sup, 'zh-CN'));
    eq('zh-CN', Locale::negotiate('fr-FR, de;q=0.5', $sup, 'zh-CN'), 'default');
    eq('en-US', Locale::negotiate('fr;q=0.9, en;q=0.8', $sup, 'zh-CN'), 'skip unsupported, keep q order');
    eq('zh-CN', Locale::negotiate('ja, *;q=0.1', $sup, 'zh-CN'));
    eq('zh-CN', Locale::negotiate('en;q=0.5, zh;q=0.8', $sup, 'zh-CN'), 'higher q wins');
    eq('en', Locale::negotiate('en-AU', ['zh-CN', 'en', 'en-US'], 'zh-CN'), 'truncation before primary-language match');
    eq('zh-CN', Locale::negotiate('', $sup, 'zh-CN'));
});

// ---------------------------------------------------------------- formatter
t('placeholders', function (): void {
    eq('Deleted 3 items', MessageFormatter::format('Deleted {n} items', ['n' => 3]));
    eq('a {missing} b', MessageFormatter::format('a {missing} b', []));
    eq('x=, y=true, z=[1,2]', MessageFormatter::format('x={x}, y={y}, z={z}', ['x' => null, 'y' => true, 'z' => [1, 2]]));
});
t('pluralization', function (): void {
    eq('{count} item', MessageFormatter::choose('{count} item|{count} items', 1));
    eq('{count} items', MessageFormatter::choose('{count} item|{count} items', 0));
    eq('no apples', MessageFormatter::choose('{0} no apples|{1} one apple|[2,*] {count} apples', 0));
    eq('one apple', MessageFormatter::choose('{0} no apples|{1} one apple|[2,*] {count} apples', 1));
    eq('{count} apples', MessageFormatter::choose('{0} no apples|{1} one apple|[2,*] {count} apples', 7));
    eq('{count} 条', MessageFormatter::choose('{count} 条', 5), 'single form');
    eq('a|b', MessageFormatter::choose('a\|b', 2), 'escaped pipe');
});

// ---------------------------------------------------------------- catalog
t('catalog: directories merge, later override, php/json/sub-dirs, nested keys', function () use ($fx): void {
    $c = Catalog::fromDirectories("{$fx}/base", "{$fx}/app", "{$fx}/nested");
    eq(['en', 'en-US', 'ja', 'zh-CN'], $c->locales());
    eq('Remove', $c->get('en-US', '删除'), 'app dir overrides base');
    eq('Tenant (dir)', $c->get('en-US', '租户'), '<locale>/*.json after <locale>.php');
    eq('Wrong username or password', $c->get('en-US', 'auth.failed'));
    eq('Administrators', $c->get('en-US', 'menu.admins'));
    eq('{count} message|{count} messages', $c->get('en-US', '{count} 条消息'), 'list = plural forms');
    eq('one', $c->get('en-US', '1'), 'numeric key');
    eq('Cancel', $c->get('en', '取消'));
    eq(null, $c->get('en-US', '取消'));
    eq('保存する', $c->get('ja', '保存'));
    $c->add('en-US', ['删除' => 'Erase']);
    eq('Erase', $c->get('en-us', '删除'), 'add() after dirs overrides; locale normalised');
});
t('catalog: invalid packs', function () use ($fx): void {
    throws(InvalidLanguagePack::class, fn () => Catalog::fromDirectories("{$fx}/bad")->all('en-US'), 'invalid JSON');
    throws(InvalidLanguagePack::class, fn () => new Catalog(['en-US' => ['k' => new stdClass()]]), 'must be a string');
    throws(InvalidLanguagePack::class, fn () => new Catalog(['not a locale' => []]), 'invalid locale');
});

// ---------------------------------------------------------------- translator
t('translator: fallback chain en-US → en → zh-CN → key', function () use ($make): void {
    $t = $make();
    eq(['zh-CN', 'en', 'en-US', 'ja'], $t->supported());
    eq('Save', $t->get('保存', [], 'en-US'), 'en-US beats en');
    eq('English only', $t->get('仅英文', [], 'en-US'), 'from en');
    eq('Cancel', $t->get('取消', [], 'en-GB'), 'en-GB → en');
    eq('未翻译', $t->get('未翻译', [], 'en-US'), 'source key returned');
    eq('用户名或密码错误', $t->get('auth.failed', [], 'fr'), 'unsupported → zh-CN pack');
    eq('保存', $t->get('保存', [], 'zh-CN'));
    eq('用户名或密码错误', $t->get('auth.failed', [], 'zh-CN'));
    eq('Locked for 5 minutes', $t->get('auth.locked', ['minutes' => 5], 'en-US'));
    eq('Deleted 2 items', $t->get('已删除 {n} 条', ['n' => 2], 'en-US'));
    eq('已删除 2 条', $t->get('已删除 {n} 条', ['n' => 2], 'zh-CN'));
});
t('translator: choice', function () use ($make): void {
    $t = $make();
    eq('1 message', $t->choice('{count} 条消息', 1, [], 'en-US'));
    eq('4 messages', $t->choice('{count} 条消息', 4, [], 'en-US'));
    eq('4 条消息', $t->choice('{count} 条消息', 4, [], 'zh-CN'));
    eq('no apples', $t->choice('苹果', 0, [], 'en-US'));
    eq('12 apples', $t->choice('苹果', 12, [], 'en-US'));
});
t('translator: has / missingKeys / unusedKeys / missing recorder', function () use ($make): void {
    $t = $make();
    ok($t->has('保存', 'en-US'));
    ok(!$t->has('未翻译', 'en-US'));
    ok($t->has('未翻译', 'zh-CN'), 'source keys: fallback locale needs no entry');
    eq(['未翻译', '再来'], $t->missingKeys(['保存', '未翻译', '未翻译', '再来', '取消'], 'en-US'));
    eq(0, count($t->missing), 'missingKeys records nothing');
    $t->get('未翻译', [], 'en-US');
    $t->get('未翻译', [], 'en-US');
    $t->get('保存', [], 'en-US');
    $t->get('未翻译', [], 'zh-CN');
    eq(['en-US' => ['未翻译']], $t->missing->all());
    ok(in_array('menu.system', $t->unusedKeys('en-US', ['保存']), true));
    $strict = new Translator(new Catalog(['zh-CN' => ['a' => '甲']]), 'zh-CN', sourceKeys: false);
    eq('b', $strict->get('b', [], 'zh-CN'));
    eq(['zh-CN' => ['b']], $strict->missing->all(), 'symbolic keys: missing in the fallback locale too');
    ok(!$strict->has('b', 'zh-CN'));
});
t('missing recorder: bounded + callback', function (): void {
    $seen = [];
    $m = new MissingTranslations(3, function (string $l, string $k) use (&$seen): void { $seen[] = "{$l}:{$k}"; });
    foreach (['a', 'b', 'a', 'c', 'd'] as $k) {
        $m->record('en-US', $k);
    }
    eq(3, count($m));
    eq(['en-US:a', 'en-US:b', 'en-US:c'], $seen);
    $m->clear();
    eq([], $m->all());
});
t('translator: explicit supported list + negotiate', function (): void {
    $t = new Translator(new Catalog(['en-US' => ['保存' => 'Save'], 'ja' => []]), 'zh-CN', ['en-US']);
    eq(['zh-CN', 'en-US'], $t->supported());
    eq('zh-CN', $t->negotiate('ja'));
    eq('en-US', $t->negotiate('en'));
    ok($t->isSupported('en_us') && !$t->isSupported('ja'));
});
t('current locale: LocaleContext + Lang facade + __()', function () use ($make): void {
    $t = $make();
    Lang::setTranslator($t);
    LocaleContext::set(null);
    eq('zh-CN', $t->locale());
    eq('保存', __('保存'));
    LocaleContext::set('en-us');
    eq('en-US', LocaleContext::get());
    eq('Save', __('保存'));
    eq('2 messages', trans_choice('{count} 条消息', 2));
    eq('Deleted 1 items', Lang::get('已删除 {n} 条', ['n' => 1]));
    LocaleContext::set('en-AU');
    eq('en', $t->locale(), 'context tag negotiated against supported');
    $r = LocaleContext::run('zh-CN', fn () => [__('保存'), LocaleContext::get()]);
    eq(['保存', 'zh-CN'], $r);
    eq('en-AU', LocaleContext::get(), 'run() restores');
    LocaleContext::set(null);
    Lang::setTranslator(null);
});

// ---------------------------------------------------------------- integrations
t('render adapter (when loongs/render is available)', function () use ($make): void {
    if (!interface_exists(\Loongs\Render\Contract\Translator::class)) {
        echo "    (skipped: loongs/render with Contract\\Translator not found)\n";

        return;
    }
    $a = new \Loongs\Language\Integration\Render\RenderTranslator($make());
    ok($a instanceof \Loongs\Render\Contract\Translator);
    eq('Save', $a->translate('保存', 'en-US'));
    eq(null, $a->translate('未翻译', 'en-US'));
    eq(null, $a->translate('保存', 'zh-CN'));
});

$coTests = function () use ($make, $fx): void {
    t('coroutines: per-coroutine locale, children inherit, no leaking between requests', function () use ($make): void {
        $t = $make();
        $out = [];
        $wg = new Swoole\Coroutine\WaitGroup();
        foreach (['en-US' => 30, 'zh-CN' => 10, 'en' => 20] as $locale => $ms) {
            $wg->add();
            go(function () use ($t, $locale, $ms, &$out, $wg): void {
                LocaleContext::set($locale);
                Swoole\Coroutine::sleep($ms / 1000);   // interleave
                $child = null;
                $wg2 = new Swoole\Coroutine\WaitGroup(1);
                go(function () use ($t, &$child, $wg2): void {
                    Swoole\Coroutine::sleep(0.005);
                    $child = $t->get('保存');
                    $wg2->done();
                });
                $wg2->wait();
                $out[$locale] = [$t->locale(), $t->get('保存'), $child];
                $wg->done();
            });
        }
        $wg->wait();
        ksort($out);
        eq(['en' => ['en', 'Save (en)', 'Save (en)'], 'en-US' => ['en-US', 'Save', 'Save'], 'zh-CN' => ['zh-CN', '保存', '保存']], $out);
        $fresh = null;
        $wg3 = new Swoole\Coroutine\WaitGroup(1);
        go(function () use ($t, &$fresh, $wg3): void {   // a new "request" coroutine sees no locale
            $fresh = [LocaleContext::get(), $t->locale()];
            $wg3->done();
        });
        $wg3->wait();
        eq([null, 'zh-CN'], $fresh);
    });
    t('framework LocaleMiddleware (when loongs/framework is available)', function () use ($make): void {
        if (!interface_exists(\Loongs\Middleware\MiddlewareInterface::class)) {
            echo "    (skipped: loongs/framework not found)\n";

            return;
        }
        $t = $make();
        $mw = new LocaleMiddleware($t);
        $req = static function (array $headers, array $get = []): \Loongs\Http\Request {
            $s = new Swoole\Http\Request();
            $s->header = $headers;
            $s->get = $get;
            $s->server = ['request_method' => 'GET', 'request_uri' => '/x'];

            return new \Loongs\Http\Request($s);
        };
        $seen = [];
        $next = function (\Loongs\Http\Request $r) use ($t, &$seen): \Loongs\Http\Response {
            $seen[] = [$r->getAttribute('locale'), $t->get('保存')];

            return (new \Loongs\Http\Response())->header('Vary', 'Authorization');
        };
        $r1 = $mw->handle($req(['accept-language' => 'en-GB,en;q=0.9']), $next);
        $r2 = $mw->handle($req(['accept-language' => 'en-US'], ['ui_locales' => 'fr zh-CN']), $next);
        $r3 = $mw->handle($req([]), $next);
        eq([['en', 'Save (en)'], ['zh-CN', '保存'], ['zh-CN', '保存']], $seen);
        $h = array_change_key_case($r1->getHeaders());
        eq('en', $h['content-language']);
        eq('Authorization, Accept-Language', $h['vary']);
        eq('zh-CN', array_change_key_case($r2->getHeaders())['content-language']);
        eq(null, LocaleContext::get(), 'restored after the request');
    });
};

if ($mode === 'co') {
    if (!extension_loaded('swoole')) {
        echo "swoole not loaded — co mode skipped\n";
        exit(0);
    }
    $coTests();
}

$run = function () use (&$tests, &$passed, &$failed): void {
    foreach ($tests as [$name, $fn]) {
        try {
            $fn();
            $passed++;
            echo "  ok  {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL {$name}\n       " . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
};
echo "loongs/language tests ({$mode})\n";
if ($mode === 'co') {
    Swoole\Coroutine\run($run);
} else {
    $run();
}
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
