<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AllTokensDefined extends Constraint
{
    public string $message = 'zangra_barcode_print.template.tokens.undefined';

    public function validatedBy(): string
    {
        return AllTokensDefinedValidator::class;
    }

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
