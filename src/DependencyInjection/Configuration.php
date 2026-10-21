<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('zangra_sylius_barcode_print');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->children()
                ->scalarNode('templates_path')
                    ->info('Directory containing the ZPL label template files.')
                    ->cannotBeEmpty()
                    ->defaultValue('%kernel.project_dir%/labels')
                ->end()
                ->scalarNode('ean_property_path')
                    ->info('Property path of the product variant holding the EAN; variants without a value are not printable.')
                    ->cannotBeEmpty()
                    ->defaultValue('ean')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
