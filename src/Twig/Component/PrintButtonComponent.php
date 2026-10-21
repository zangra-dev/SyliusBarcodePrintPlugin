<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Twig\Component;

use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;
use Zangra\SyliusBarcodePrintPlugin\Label\IconRegistry;
use Zangra\SyliusBarcodePrintPlugin\Label\PrintableOrderItems;

/**
 * Hookable "Print label" button. Usage:
 *
 *     {{ component('zangra_barcode_print:print_button', { defaults: { code: 'abc', ean13: '5415249002848' } }) }}
 *
 * With `order`, the dialog lists the printable items of the order (variants with an EAN): one row per item with a
 * checkbox, the code and a quantity field, and one label job is printed for every selected row. Code, EAN and quantity
 * come from the order item, extra variable values from the tagged PrintableItemVariablesProviderInterface services:
 *
 *     {{ component('zangra_barcode_print:print_button', { order: order }) }}
 *
 * `items` provides the rows directly instead:
 *
 *     {{ component('zangra_barcode_print:print_button', { items: [{ code: 'abc', ean: '5415249002848', quantity: 4 }] }) }}
 */
final class PrintButtonComponent
{
    /** @var array<string, string|list<string>> prefilled values, keyed by variable key */
    public array $defaults = [];

    /** Restricts the button to a single template (by code) when set. */
    public ?string $templateCode = null;

    public ?string $label = null;

    /** Order whose printable items are listed (batch mode). */
    public ?OrderInterface $order = null;

    /** @var list<array{code: string, ean?: string, quantity: int, variables?: array<string, string|list<string>>}> rows of the batch dialog */
    public array $items = [];

    /**
     * @param ObjectRepository<LabelTemplate> $templateRepository
     * @param ObjectRepository<LabelPrinter>  $printerRepository
     */
    public function __construct(
        private readonly ObjectRepository $templateRepository,
        private readonly ObjectRepository $printerRepository,
        private readonly IconRegistry $icons,
        private readonly PrintableOrderItems $printableOrderItems,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function getTemplates(): array
    {
        $criteria = ['enabled' => true];
        if (null !== $this->templateCode && '' !== $this->templateCode) {
            $criteria['code'] = $this->templateCode;
        }

        $templates = [];
        /** @var LabelTemplate $template */
        foreach ($this->templateRepository->findBy($criteria, ['name' => 'ASC']) as $template) {
            $variables = [];
            foreach ($template->getVariables() as $variable) {
                $variables[] = [
                    'key' => $variable->getKey(),
                    'label' => $variable->getLabel(),
                    'type' => $variable->getType()->value,
                    'default' => $variable->getDefaultValue(),
                    'required' => $variable->isRequired(),
                ];
            }

            $templates[] = [
                'code' => $template->getCode(),
                'name' => $template->getName(),
                'default' => $template->isDefault(),
                'variables' => $variables,
            ];
        }

        return $templates;
    }

    /** @return list<array<string, string|bool|null>> */
    public function getPrinters(): array
    {
        $printers = [];
        /** @var LabelPrinter $printer */
        foreach ($this->printerRepository->findBy(['enabled' => true], ['name' => 'ASC']) as $printer) {
            $printers[] = [
                'code' => $printer->getCode(),
                'name' => $printer->getName(),
                'transport' => $printer->getTransport()->value,
                'default' => $printer->isDefault(),
            ];
        }

        return $printers;
    }

    /** The dialog lists rows (batch mode) instead of a single quantity field when an order or items are given. */
    public function isBatch(): bool
    {
        return null !== $this->order || [] !== $this->items;
    }

    /** @return list<array{code: string, ean: string, quantity: int, variables: array<string, string|list<string>>}> */
    public function getRows(): array
    {
        if ([] === $this->items) {
            return null === $this->order ? [] : $this->printableOrderItems->forOrder($this->order);
        }

        $rows = [];
        foreach ($this->items as $item) {
            $rows[] = [
                'code' => (string) $item['code'],
                'ean' => (string) ($item['ean'] ?? ''),
                'quantity' => max(1, (int) $item['quantity']),
                'variables' => PrintableOrderItems::sanitizeVariables($item['variables'] ?? []),
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public function getIcons(): array
    {
        return $this->icons->keys();
    }
}
