<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Form;

use Azymuthia\TurnstileBundle\Twig\WidgetOptions;
use Azymuthia\TurnstileBundle\Validator\Turnstile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Renders the Turnstile widget, which posts its token under this field's name, and validates the
 * token unless `turnstile_validation` is false. Tokens are single-use: a form that must check
 * something else first renders without validation and calls TurnstileVerifier itself afterwards.
 */
final class TurnstileType extends AbstractType
{
    public function __construct(
        private readonly WidgetOptions $widgetOptions,
    ) {
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['turnstile'] = $this->widgetOptions->resolve(
            $options['turnstile_action'],
            $view->vars['full_name'],
            $options['turnstile_theme'],
            $options['turnstile_size'],
            $options['turnstile_language'],
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('turnstile_action');
        $resolver->setDefaults([
            'mapped' => false,
            'label' => false,
            'turnstile_validation' => true,
            'turnstile_theme' => 'auto',
            'turnstile_size' => 'flexible',
            'turnstile_language' => null,
            'constraints' => static fn (Options $options): array => $options['turnstile_validation']
                ? [new Turnstile($options['turnstile_action'])]
                : [],
        ]);
        $resolver->setAllowedTypes('turnstile_action', 'string');
        $resolver->setAllowedTypes('turnstile_validation', 'bool');
        $resolver->setAllowedValues('turnstile_theme', WidgetOptions::THEMES);
        $resolver->setAllowedValues('turnstile_size', WidgetOptions::SIZES);
        $resolver->setAllowedTypes('turnstile_language', ['null', 'string']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'turnstile';
    }
}
