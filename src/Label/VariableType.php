<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

enum VariableType: string
{
    case Text = 'text';
    case Ean13 = 'ean13';
    case Icons = 'icons';
}
