<?php

/*
 * This file is part of the CsaGuzzleBundle package
 *
 * (c) Charles Sarrazin <charles@sarraz.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code
 */

namespace Csa\Bundle\GuzzleBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Csa Guzzle Extension
 *
 * @author Charles Sarrazin <charles@sarraz.in>
 */
class CsaGuzzleExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        $loader->load('subscribers.xml');
        $loader->load('collector.xml');
        $loader->load('twig.xml');
        $loader->load('factory.xml');

        $loader->load('services.xml');

        $descriptionFactory = $container->getDefinition('csa_guzzle.description_factory');

        $dataCollector = $container->getDefinition('csa_guzzle.data_collector.guzzle');
        $dataCollector->addArgument($config['profiler']['max_body_size']);

        if (!$config['profiler']['enabled']) {
            $container->removeDefinition('csa_guzzle.subscriber.debug');
            $container->removeDefinition('csa_guzzle.subscriber.stopwatch');
            $container->removeDefinition('csa_guzzle.data_collector.guzzle');
            $container->removeDefinition('csa_guzzle.twig.extension');
        }


        $loggerDefinition = $container->getDefinition('csa_guzzle.subscriber.logger');

        if ($config['logger']['service']) {
            $loggerDefinition->replaceArgument(0, new Reference($config['logger']['service']));
        }

        if ($config['logger']['format']) {
            $loggerDefinition->replaceArgument(1, $config['logger']['format']);
        }

        if (!$config['logger']['enabled']) {
            $container->removeDefinition('csa_guzzle.subscriber.logger');
        }

        $this->processCacheConfiguration($config['cache'], $container);

        $definition = $container->getDefinition('csa_guzzle.client_factory');
        $definition->replaceArgument(0, $config['factory_class']);

        $this->processClientsConfiguration($config, $container, $definition, $descriptionFactory);
    }

    private function processCacheConfiguration(array $config, ContainerBuilder $container)
    {
        if (!$config['enabled']) {
            $container->removeDefinition('csa_guzzle.subscriber.cache');

            return;
        }

        $adapterId = ('custom' === $config['adapter']['type'])
            ? $config['adapter']['service']
            : sprintf('csa_guzzle.cache.adapter.%s', $config['adapter']['type']);

        $adapter = $container->getDefinition($adapterId);
        $adapter->addArgument(new Reference($config['service']));
        $container->setAlias('csa_guzzle.default_cache_adapter', $adapterId);
    }

    private function processClientsConfiguration(array $config, ContainerBuilder $container, Definition $clientFactory, Definition $descriptionFactory)
    {
        foreach ($config['clients'] as $name => $options) {
            $clientFactory->addMethodCall('registerClientConfiguration', [
                $name,
                $options['config'],
                $options['subscribers']
            ]);

            $client = new DefinitionDecorator('csa_guzzle.client.abstract');
            $client->setFactoryService('csa_guzzle.client_factory');
            $client->setClass($config['factory_class']);
            $client->setFactoryMethod('createNamed');
            $client->setArguments([$name]);

            $clientServiceId = sprintf('csa_guzzle.client.%s', $name);
            $container->setDefinition($clientServiceId, $client);

            if (isset($options['description'])) {
                $descriptionFactory->addMethodCall('addResource', [$name, $options['description']]);

                $serviceDefinition = new DefinitionDecorator('csa_guzzle.service.abstract');
                $serviceDefinition->addArgument(new Reference($clientServiceId));
                $serviceDefinition->addArgument(new Expression(sprintf(
                    'service("csa_guzzle.description_factory").getDescription("%s")',
                    $name
                )));
                $container->setDefinition(sprintf('csa_guzzle.service.%s', $name), $serviceDefinition);
            }
        }
    }
}
