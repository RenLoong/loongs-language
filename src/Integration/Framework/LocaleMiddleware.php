<?php

declare(strict_types=1);

namespace Loongs\Language\Integration\Framework;

use Closure;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Language\LocaleContext;
use Loongs\Language\Translator;
use Loongs\Middleware\MiddlewareInterface;

/**
 * loongs/framework middleware (needs loongs/framework): picks the request locale and makes it the
 * current locale (LocaleContext) for the rest of the pipeline.
 *
 * Order: the first query / form parameter in $params that names a supported locale (default "lang"
 * and "ui_locales", the OpenID Connect authorize parameter), else Accept-Language, else the
 * translator's fallback. The locale is also stored as request attribute "locale". Responses get
 * Content-Language and "Vary: Accept-Language" (merged with an existing Vary).
 *
 * Register it before middleware that renders messages (error handlers), e.g. in apps/<App>/middleware.php.
 * Bind Translator in the container (it is the only constructor dependency without a default).
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    /** @param list<string> $params */
    public function __construct(
        private readonly Translator $translator,
        private readonly array $params = ['lang', 'ui_locales'],
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);
        $request->setAttribute('locale', $locale);
        $previous = LocaleContext::get();
        LocaleContext::set($locale);
        try {
            $response = $next($request);
        } finally {
            LocaleContext::set($previous);
        }

        return self::decorate($response, $locale);
    }

    public function resolve(Request $request): string
    {
        foreach ($this->params as $p) {
            $v = $request->query($p) ?? $request->input($p);
            if (!is_string($v) || $v === '') {
                continue;
            }
            // ui_locales is a space-separated preference list
            foreach (preg_split('/[\s,]+/', trim($v)) ?: [] as $tag) {
                if ($tag !== '' && $this->translator->isSupported($tag)) {
                    return $this->translator->negotiate($tag);
                }
            }
        }

        return $this->translator->negotiate($request->header('accept-language'));
    }

    public static function decorate(Response $response, string $locale): Response
    {
        $vary = '';
        $hasLanguage = false;
        foreach ($response->getHeaders() as $name => $value) {
            $name = strtolower((string) $name);
            if ($name === 'vary') {
                $vary = (string) (is_array($value) ? implode(', ', $value) : $value);
            } elseif ($name === 'content-language') {
                $hasLanguage = true;
            }
        }
        if (!$hasLanguage) {
            $response->header('Content-Language', $locale);
        }
        if (!in_array('accept-language', array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $vary)), true) && trim($vary) !== '*') {
            $response->header('Vary', trim($vary) === '' ? 'Accept-Language' : $vary . ', Accept-Language');
        }

        return $response;
    }
}
