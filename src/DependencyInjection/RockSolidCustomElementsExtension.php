<?php

namespace MadeYourDay\RockSolidCustomElements\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class RockSolidCustomElementsExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );

        try {
            $loader->load('services.yaml');
            $loader->load('listener.yaml');
        } catch (\Exception $e) {
            echo($e->getMessage());
            exit();
        }
    }
}
