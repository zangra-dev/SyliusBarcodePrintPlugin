<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class TemplatesPathExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly string $templatesPath)
    {
    }

    public function getGlobals(): array
    {
        return ['zangra_barcode_print_templates_path' => $this->templatesPath];
    }
}
