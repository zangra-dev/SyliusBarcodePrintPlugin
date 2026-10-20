<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label\Exception;

final class InvalidLabelValuesException extends LabelException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid label values: ' . json_encode($errors, \JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
