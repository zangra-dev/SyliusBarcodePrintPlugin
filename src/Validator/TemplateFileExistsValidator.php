<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Zangra\SyliusBarcodePrintPlugin\Label\TemplateFileLocator;

final class TemplateFileExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly TemplateFileLocator $locator)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TemplateFileExists) {
            throw new UnexpectedTypeException($constraint, TemplateFileExists::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value) || !$this->locator->exists($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ path }}', \is_string($value) ? $value : '')
                ->setParameter('{{ directory }}', $this->locator->getTemplatesPath())
                ->addViolation()
            ;
        }
    }
}
