<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional\App;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Holds one user, `alice` with the password `secret`, and counts how often a user is looked up.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final class SpyUserProvider implements UserProviderInterface
{
    public int $lookups = 0;

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        ++$this->lookups;

        if ('alice' !== $identifier) {
            throw new UserNotFoundException();
        }

        return new InMemoryUser('alice', 'secret', ['ROLE_USER']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return new InMemoryUser($user->getUserIdentifier(), 'secret', ['ROLE_USER']);
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
