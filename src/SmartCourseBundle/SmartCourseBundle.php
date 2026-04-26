<?php

namespace App\SmartCourseBundle;

use App\SmartCourseBundle\DependencyInjection\SmartCourseExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * SmartCourseBundle
 *
 * A plug-and-play Symfony bundle providing:
 *   - Intelligent course recommendation engine (hybrid scoring)
 *   - Course analytics (views, enrolments, completion rates)
 *   - Event-driven activity tracking (CourseViewedEvent, CourseEnrolledEvent)
 *   - Email notification hooks
 *   - REST API endpoints for recommendations, trending, and search
 *
 * Install in any Symfony project and configure via config/packages/smart_course.yaml.
 *
 * Usage:
 *   $courses = $recommendationService->getRecommendations($userId);
 */
class SmartCourseBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SmartCourseExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}
