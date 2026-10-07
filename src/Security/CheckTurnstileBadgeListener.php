<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Security;

use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Verifies a passport's TurnstileBadge, on every firewall.
 *
 * Runs at priority 300 on CheckPassportEvent: after LoginThrottlingListener (2080) and
 * CsrfProtectionListener (512), before UserCheckerListener (256) loads the user and
 * CheckCredentialsListener (0) hashes the password.
 */
final readonly class CheckTurnstileBadgeListener
{
    public const int PRIORITY = 300;

    public function __construct(
        private TurnstileVerifier $verifier,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$passport->hasBadge(TurnstileBadge::class)) {
            return;
        }

        /** @var TurnstileBadge $badge */
        $badge = $passport->getBadge(TurnstileBadge::class);

        if ($badge->isResolved()) {
            return;
        }

        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            throw new TurnstileAuthenticationException();
        }

        $result = $this->verifier->verify($badge->getToken(), $badge->getAction(), $request);

        if (!$result->isSuccess()) {
            throw new TurnstileAuthenticationException($result->getErrorCodes());
        }

        $badge->markResolved();
    }
}
