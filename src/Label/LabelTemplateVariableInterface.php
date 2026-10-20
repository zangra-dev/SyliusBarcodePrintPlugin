<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

interface LabelTemplateVariableInterface
{
    public function getKey(): string;

    public function getType(): VariableType;

    public function getDefaultValue(): ?string;

    public function isRequired(): bool;
}
