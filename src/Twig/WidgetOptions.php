<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Twig;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the variables of `@AzymuthiaTurnstile/widget.html.twig`, shared by the form theme and the Twig function.
 *
 * @internal
 */
final readonly class WidgetOptions
{
    public const array THEMES = ['auto', 'light', 'dark'];
    public const array SIZES = ['flexible', 'normal', 'compact'];
    public const string DEFAULT_FIELD = 'cf-turnstile-response';

    public function __construct(
        private string $siteKey,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array{site_key: string, action: string, field_name: string, theme: string, size: string, language: string}
     */
    public function resolve(
        string $action,
        string $fieldName = self::DEFAULT_FIELD,
        string $theme = 'auto',
        string $size = 'flexible',
        ?string $language = null,
    ): array {
        if (!\in_array($theme, self::THEMES, true)) {
            throw new \InvalidArgumentException(\sprintf('The Turnstile theme must be one of "%s", "%s" given.', implode('", "', self::THEMES), $theme));
        }

        if (!\in_array($size, self::SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('The Turnstile size must be one of "%s", "%s" given.', implode('", "', self::SIZES), $size));
        }

        return [
            'site_key' => $this->siteKey,
            'action' => $action,
            'field_name' => $fieldName,
            'theme' => $theme,
            'size' => $size,
            'language' => $language ?? $this->requestStack->getCurrentRequest()?->getLocale() ?? 'auto',
        ];
    }
}
