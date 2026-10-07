<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional\App;

use Azymuthia\TurnstileBundle\Form\TurnstileType;
use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

final readonly class AppController
{
    public function __construct(
        private Environment $twig,
        private Security $security,
        private FormFactoryInterface $formFactory,
        private TurnstileVerifier $verifier,
    ) {
    }

    public function home(): Response
    {
        return new Response('Logged in as '.($this->security->getUser()?->getUserIdentifier() ?? 'nobody'));
    }

    public function custom(): Response
    {
        return new Response('Custom firewall: '.($this->security->getUser()?->getUserIdentifier() ?? 'nobody'));
    }

    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return new Response($this->twig->render('login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]));
    }

    public function reset(Request $request): Response
    {
        $form = $this->resetForm($request, [
            'turnstile_theme' => $request->query->getString('theme', 'auto'),
            'turnstile_size' => $request->query->getString('size', 'flexible'),
            'turnstile_language' => $request->query->get('language'),
        ]);

        if ($form->isSubmitted() && $form->isValid()) {
            return new Response('Sent');
        }

        return new Response($this->twig->render('form.html.twig', ['form' => $form->createView()]));
    }

    /**
     * Something checked before Turnstile, then the single-use token verified afterwards.
     */
    public function resetLate(Request $request): Response
    {
        $form = $this->resetForm($request, ['turnstile_validation' => false]);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return new Response($this->twig->render('form.html.twig', ['form' => $form->createView()]));
        }

        $token = $form->get('turnstile')->getData();
        $result = $this->verifier->verify(\is_string($token) ? $token : null, 'password_reset', $request);

        return new JsonResponse(['success' => $result->isSuccess(), 'error_codes' => $result->getErrorCodes()]);
    }

    /**
     * @param array<string, mixed> $turnstileOptions
     *
     * @return FormInterface<mixed>
     */
    private function resetForm(Request $request, array $turnstileOptions): FormInterface
    {
        $form = $this->formFactory->createNamedBuilder('reset', FormType::class, options: ['csrf_protection' => false])
            ->add('email', TextType::class)
            ->add('turnstile', TurnstileType::class, ['turnstile_action' => 'password_reset', ...$turnstileOptions])
            ->getForm();
        $form->handleRequest($request);

        return $form;
    }
}
