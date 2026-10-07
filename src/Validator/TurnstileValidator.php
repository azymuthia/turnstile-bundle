<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Validator;

use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TurnstileValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TurnstileVerifier $verifier,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Turnstile) {
            throw new UnexpectedTypeException($constraint, Turnstile::class);
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$this->verifier->verify(\is_string($value) ? $value : null, $constraint->action, $request)->isSuccess()) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
