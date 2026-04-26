<?php

namespace App\SmartCourseBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the configuration tree for smart_course.yaml.
 *
 * Full example:
 *
 *   smart_course:
 *     recommendation:
 *       enabled: true
 *       strategy: hybrid          # hybrid | content | popularity
 *       weights:
 *         similarity: 0.5
 *         popularity: 0.3
 *         history:    0.2
 *     notifications:
 *       email: true
 *     analytics:
 *       enabled: true
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('smart_course');
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('recommendation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->enumNode('strategy')
                            ->values(['hybrid', 'content', 'popularity'])
                            ->defaultValue('hybrid')
                        ->end()
                        ->arrayNode('weights')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->floatNode('similarity')->defaultValue(0.5)->end()
                                ->floatNode('popularity')->defaultValue(0.3)->end()
                                ->floatNode('history')->defaultValue(0.2)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('notifications')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('email')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('analytics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
