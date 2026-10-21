<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * Extension point for host applications: supplies extra label template variable values for a printable order item
 * (for example `madeIn` taken from a product attribute). The values are sent along when the label is rendered but are
 * never shown as editable fields. Implementations are autoconfigured; use the tag
 * `zangra_sylius_barcode_print.item_variables_provider` (with an optional `priority`) when autoconfiguration is off.
 *
 * The keys `code` and `ean13` always come from the order item and cannot be overridden. A variable that is left out
 * keeps the default value of the label template.
 */
interface PrintableItemVariablesProviderInterface
{
    public const TAG = 'zangra_sylius_barcode_print.item_variables_provider';

    /** @return array<string, string|list<string>> template variable key => value (e.g. ['madeIn' => 'Belgium']) */
    public function provide(OrderItemInterface $item): array;
}
