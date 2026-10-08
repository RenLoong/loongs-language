<?php

declare(strict_types=1);

namespace Loongs\Language\Integration\Render;

use Loongs\Language\Translator;
use Loongs\Render\Contract\Translator as RenderTranslatorContract;

/**
 * Adapter for loongs/render (needs loongs/render): page descriptions are translated with this
 * package's packs —  new PageRegistry(..., translator: new RenderTranslator($translator)).
 * Returns null when the source text should stay (no pack entry), so render keeps the original.
 */
final class RenderTranslator implements RenderTranslatorContract
{
    public function __construct(
        private readonly Translator $translator,
    ) {
    }

    public function translate(string $text, string $locale): ?string
    {
        return $this->translator->lookup($text, $locale);
    }
}
