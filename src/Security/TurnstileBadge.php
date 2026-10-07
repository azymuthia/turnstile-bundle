<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Security;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * Requires a Turnstile token issued for the given action before the passport's credentials are checked.
 *
 * Added for `form_login` on the firewalls listed under `azymuthia_turnstile.firewalls`; a custom
 * authenticator can add it to its passport itself. An unresolved badge fails the login.
 */
final class TurnstileBadge implements BadgeInterface
{
    private bool $resolved = false;

    public function __construct(
        private readonly ?string $token,
        private readonly string $action,
    ) {
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function markResolved(): void
    {
        $this->resolved = true;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
