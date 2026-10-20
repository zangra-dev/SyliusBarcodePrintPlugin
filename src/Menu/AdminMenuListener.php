<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function addAdminMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();
        $configuration = $menu->getChild('configuration') ?? $menu->addChild('configuration');

        $configuration
            ->addChild('zangra_barcode_print_templates', [
                'route' => 'zangra_barcode_print_admin_label_template_index',
                'extras' => ['routes' => [
                    ['route' => 'zangra_barcode_print_admin_label_template_create'],
                    ['route' => 'zangra_barcode_print_admin_label_template_update'],
                ]],
            ])
            ->setLabel('zangra_barcode_print.menu.label_templates')
            ->setLabelAttribute('icon', 'tabler:barcode')
        ;

        $configuration
            ->addChild('zangra_barcode_print_printers', [
                'route' => 'zangra_barcode_print_admin_label_printer_index',
                'extras' => ['routes' => [
                    ['route' => 'zangra_barcode_print_admin_label_printer_create'],
                    ['route' => 'zangra_barcode_print_admin_label_printer_update'],
                ]],
            ])
            ->setLabel('zangra_barcode_print.menu.label_printers')
            ->setLabelAttribute('icon', 'tabler:printer')
        ;
    }
}
