<?php

namespace App\Validator;

use App\Resource\Admin\AdminSiteCanonicalization;
use App\Service\Canonical\CanonicalProfileRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidCanonicalizationValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CanonicalProfileRegistry $profileRegistry,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCanonicalization) {
            throw new UnexpectedTypeException($constraint, ValidCanonicalization::class);
        }
        if (!$value instanceof AdminSiteCanonicalization) {
            return;
        }

        if (null !== $value->profile && !$this->profileRegistry->has($value->profile)) {
            $this->context->buildViolation('Unknown canonicalization profile "{{ value }}"; known: {{ profiles }}.')
                ->setParameter('{{ value }}', $value->profile)
                ->setParameter('{{ profiles }}', implode(', ', $this->profileRegistry->names()))
                ->atPath('profile')
                ->addViolation();

            return;
        }

        try {
            $this->profileRegistry->validate($value->toSettings());
        } catch (\InvalidArgumentException $e) {
            $this->context->buildViolation($e->getMessage())->addViolation();
        }
    }
}
