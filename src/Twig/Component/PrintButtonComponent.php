<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Twig\Component;

use Doctrine\Persistence\ObjectRepository;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;
use Zangra\SyliusBarcodePrintPlugin\Label\IconRegistry;

/**
 * Hookable "Print label" button. Usage:
 *
 *     {{ component('zangra_barcode_print:print_button', { defaults: { code: 'abc', ean13: '5415249002848' } }) }}
 */
final class PrintButtonComponent
{
    /** @var array<string, string|list<string>> prefilled values, keyed by variable key */
    public array $defaults = [];

    /** Restricts the button to a single template (by code) when set. */
    public ?string $templateCode = null;

    public ?string $label = null;

    /**
     * @param ObjectRepository<LabelTemplate> $templateRepository
     * @param ObjectRepository<LabelPrinter>  $printerRepository
     */
    public function __construct(
        private readonly ObjectRepository $templateRepository,
        private readonly ObjectRepository $printerRepository,
        private readonly IconRegistry $icons,
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
                'variables' => $variables,
            ];
        }

        return $templates;
    }

    /** @return list<array<string, string|null>> */
    public function getPrinters(): array
    {
        $printers = [];
        /** @var LabelPrinter $printer */
        foreach ($this->printerRepository->findBy(['enabled' => true], ['name' => 'ASC']) as $printer) {
            $printers[] = [
                'code' => $printer->getCode(),
                'name' => $printer->getName(),
                'transport' => $printer->getTransport()->value,
            ];
        }

        return $printers;
    }

    /** @return list<string> */
    public function getIcons(): array
    {
        return $this->icons->keys();
    }
}
