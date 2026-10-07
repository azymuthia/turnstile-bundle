<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional\App;

use Azymuthia\TurnstileBundle\Security\TurnstileAuthenticationException;
use Azymuthia\TurnstileBundle\Security\TurnstileBadge;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * An authenticator of its own, on a firewall not listed in the bundle configuration, adding the badge itself.
 */
final class CustomTurnstileAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): bool
    {
        return $request->headers->has('X-User');
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(
            new UserBadge((string) $request->headers->get('X-User')),
            [new TurnstileBadge($request->headers->get('X-Turnstile'), 'api')],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse([
            'message' => $exception->getMessage(),
            'message_key' => $exception->getMessageKey(),
            'error_codes' => $exception instanceof TurnstileAuthenticationException ? $exception->getErrorCodes() : null,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
