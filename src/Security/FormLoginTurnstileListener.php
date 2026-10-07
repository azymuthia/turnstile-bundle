<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Adds a TurnstileBadge, from a field of the posted form, to `form_login` passports of one firewall.
 *
 * Registered once per firewall under `azymuthia_turnstile.firewalls`, on that firewall's dispatcher.
 * Other authenticators on the firewall (remember-me, HTTP basic, ...) are left alone.
 */
final readonly class FormLoginTurnstileListener
{
    /** Just before CheckTurnstileBadgeListener, which verifies the badge. */
    public const int PRIORITY = CheckTurnstileBadgeListener::PRIORITY + 1;

    public function __construct(
        private RequestStack $requestStack,
        private string $action,
        private string $field,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $authenticator = $event->getAuthenticator();

        if ($authenticator instanceof TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }

        if (!$authenticator instanceof FormLoginAuthenticator) {
            return;
        }

        $passport = $event->getPassport();

        if ($passport->hasBadge(TurnstileBadge::class)) {
            return;
        }

        $token = $this->requestStack->getMainRequest()?->request->all()[$this->field] ?? null;

        $passport->addBadge(new TurnstileBadge(\is_string($token) ? $token : null, $this->action));
    }
}
