<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TemplateNotFoundException;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelRenderer;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelTemplateInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\TemplateFileLocator;

final class AllTokensDefinedValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TemplateFileLocator $locator,
        private readonly LabelRenderer $renderer,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AllTokensDefined) {
            throw new UnexpectedTypeException($constraint, AllTokensDefined::class);
        }

        if (!$value instanceof LabelTemplateInterface || '' === $value->getPath()) {
            return;
        }

        try {
            $zpl = $this->locator->read($value->getPath());
        } catch (TemplateNotFoundException) {
            return; // reported by TemplateFileExists
        }

        $defined = [LabelRenderer::TOKEN_QUANTITY];
        foreach ($value->getVariables() as $variable) {
            $defined[] = $variable->getKey();
        }

        $undefined = array_diff($this->renderer->extractTokens($zpl), $defined);
        if ([] !== $undefined) {
            $this->context->buildViolation($constraint->message)
                ->atPath('variables')
                ->setParameter('{{ tokens }}', implode(', ', $undefined))
                ->addViolation()
            ;
        }
    }
}
