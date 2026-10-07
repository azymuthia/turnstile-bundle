<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Twig;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TurnstileExtension extends AbstractExtension
{
    public function __construct(
        private readonly WidgetOptions $widgetOptions,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('azymuthia_turnstile_widget', $this->renderWidget(...), [
                'needs_environment' => true,
                'is_safe' => ['html'],
            ]),
        ];
    }

    /**
     * Renders the widget outside a Symfony form, e.g. on a `form_login` page. By default the token
     * is posted as `cf-turnstile-response`, the field the firewall integration reads.
     *
     * @param array{field_name?: string, theme?: string, size?: string, language?: string|null} $options
     */
    public function renderWidget(Environment $twig, string $action, array $options = []): string
    {
        return $twig->render('@AzymuthiaTurnstile/widget.html.twig', $this->widgetOptions->resolve(
            $action,
            $options['field_name'] ?? WidgetOptions::DEFAULT_FIELD,
            $options['theme'] ?? 'auto',
            $options['size'] ?? 'flexible',
            $options['language'] ?? null,
        ));
    }
}
