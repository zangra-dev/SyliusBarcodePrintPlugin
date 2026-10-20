<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
final class TemplateFileExists extends Constraint
{
    public string $message = 'zangra_barcode_print.template.path.not_found';

    public function validatedBy(): string
    {
        return TemplateFileExistsValidator::class;
    }
}
