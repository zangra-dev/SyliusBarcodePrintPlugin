<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

interface LabelTemplateInterface
{
    /** Path of the ZPL file, relative to the configured templates directory. */
    public function getPath(): string;

    /** @return iterable<LabelTemplateVariableInterface> */
    public function getVariables(): iterable;
}
