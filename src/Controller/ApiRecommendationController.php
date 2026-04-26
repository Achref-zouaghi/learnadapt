<?php

namespace App\Controller;

use App\Service\RecommendationService;
use App\SmartCourseBundle\Service\AnalyticsService;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Smart Course Recommendation API
 *
 * Endpoints:
 *   GET  /api/recommendations/{userId}   — personalised recommendations
 *   POST /api/user/activity              — track user activity
 *   GET  /api/courses/trending           — trending courses
 *   GET  /api/courses/search?q=          — smart search
 */
#[Route('/api', name: 'api_')]
class ApiRecommendationController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly AnalyticsService $analyticsService,
        private readonly UserRepository $userRepository,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/recommendations/{userId}
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/recommendations/{userId}', name: 'recommendations', methods: ['GET'])]
    public function recommendations(int $userId, Request $request): JsonResponse
    {
        if (!$this->isAuthorised($request, $userId)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $limit = min((int) $request->query->get('limit', 6), 20);
        $recommendations = $this->recommendationService->getRecommendations($userId, $limit);

        return $this->json([
            'status'          => 'ok',
            'user_id'         => $userId,
            'algorithm'       => 'hybrid: 0.5×similarity + 0.3×popularity + 0.2×history',
            'count'           => count($recommendations),
            'recommendations' => $recommendations,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/user/activity
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/user/activity', name: 'user_activity', methods: ['POST'])]
    public function recordActivity(Request $request): JsonResponse
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $activityType = $data['activity_type'] ?? $request->request->get('activity_type');
        $courseId     = isset($data['course_id'])     ? (int)$data['course_id']     : null;
        $searchQuery  = $data['search_query']         ?? $request->request->get('search_query');

        $allowed = ['view', 'search', 'enroll', 'complete', 'bookmark'];
        if (!in_array($activityType, $allowed, true)) {
            return $this->json([
                'error'   => 'Invalid activity_type',
                'allowed' => $allowed,
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->recommendationService->recordActivity(
            (int)$auth['id'],
            $activityType,
            $courseId,
            $searchQuery
        );

        return $this->json([
            'status'        => 'recorded',
            'activity_type' => $activityType,
            'course_id'     => $courseId,
        ], Response::HTTP_CREATED);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/courses/trending
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/courses/trending', name: 'courses_trending', methods: ['GET'])]
    public function trending(Request $request): JsonResponse
    {
        $limit   = min((int) $request->query->get('limit', 8), 20);
        $courses = $this->recommendationService->getTrending($limit);

        return $this->json([
            'status'  => 'ok',
            'ranking' => 'enrolments×0.5 + avg_rating×0.3 + recent_views×0.2',
            'count'   => count($courses),
            'courses' => $courses,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/courses/search?q=...
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/courses/search', name: 'courses_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if (strlen($query) < 2) {
            return $this->json([
                'error' => 'Query must be at least 2 characters',
            ], Response::HTTP_BAD_REQUEST);
        }

        $limit  = min((int) $request->query->get('limit', 10), 30);
        $result = $this->recommendationService->search($query, $limit);

        return $this->json(array_merge($result, ['status' => 'ok']));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ──────────────────────────────────────────────────────────────────────────
    private function isAuthorised(Request $request, int $requestedUserId): bool
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return false;
        }
        // Allow own data only
        return (int)$auth['id'] === $requestedUserId;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/analytics/course/{courseId}      — SmartCourseBundle analytics
    // GET /api/analytics/global                 — platform-wide stats
    // GET /api/analytics/user/{userId}          — user engagement
    // ──────────────────────────────────────────────────────────────────────────
    #[Route('/analytics/course/{courseId}', name: 'analytics_course', methods: ['GET'])]
    public function analyticsCourse(int $courseId): JsonResponse
    {
        return $this->json($this->analyticsService->getCourseStats($courseId));
    }

    #[Route('/analytics/global', name: 'analytics_global', methods: ['GET'])]
    public function analyticsGlobal(): JsonResponse
    {
        return $this->json($this->analyticsService->getGlobalStats());
    }

    #[Route('/analytics/user/{userId}', name: 'analytics_user', methods: ['GET'])]
    public function analyticsUser(int $userId, Request $request): JsonResponse
    {
        if (!$this->isAuthorised($request, $userId)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        return $this->json($this->analyticsService->getUserEngagement($userId));
    }
}
