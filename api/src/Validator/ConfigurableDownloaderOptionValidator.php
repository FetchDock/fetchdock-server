<?php

namespace App\Validator;

use App\Entity\DownloaderOptionPolicy;
use App\Service\Downloader\Options\OptionsPolicy;
use App\Service\Downloader\Options\OptionsSchemaFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ConfigurableDownloaderOptionValidator extends ConstraintValidator
{
    public function __construct(
        private readonly OptionsSchemaFactory $schemaFactory,
        private readonly OptionsPolicy $policy,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ConfigurableDownloaderOption) {
            throw new UnexpectedTypeException($constraint, ConfigurableDownloaderOption::class);
        }
        if (!$value instanceof DownloaderOptionPolicy || true !== $value->isExposed() || null === $value->getDownloader() || null === $value->getOption()) {
            return;
        }

        $option = $this->schemaFactory->getAllOptions($value->getDownloader())[$value->getOption()] ?? null;
        if (null !== $option && $this->policy->isHardDenied($value->getDownloader(), $option)) {
            $this->context->buildViolation($constraint->message)
                ->atPath('exposed')
                ->setParameter('{{ option }}', $value->getOption())
                ->setParameter('{{ downloader }}', $value->getDownloader())
                ->addViolation();
        }
    }
}
