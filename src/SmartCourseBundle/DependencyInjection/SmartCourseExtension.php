<?php

namespace App\SmartCourseBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads SmartCourseBundle configuration and registers it into the container.
 *
 * Config reference (config/packages/smart_course.yaml):
 *
 *   smart_course:
 *     recommendation:
 *       enabled: true
 *       strategy: hybrid
 *       weights: { similarity: 0.5, popularity: 0.3, history: 0.2 }
 *     notifications:
 *       email: true
 *     analytics:
 *       enabled: true
 */
class SmartCourseExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Expose config values as container parameters
        $container->setParameter('smart_course.recommendation.enabled',  $config['recommendation']['enabled']);
        $container->setParameter('smart_course.recommendation.strategy', $config['recommendation']['strategy']);
        $container->setParameter('smart_course.recommendation.weights',  $config['recommendation']['weights']);
        $container->setParameter('smart_course.notifications.email',     $config['notifications']['email']);
        $container->setParameter('smart_course.analytics.enabled',       $config['analytics']['enabled']);

        // Load bundle service definitions
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'smart_course';
    }
}
