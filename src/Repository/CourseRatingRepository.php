<?php

namespace App\Repository;

use App\Entity\CourseRating;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseRating>
 */
class CourseRatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseRating::class);
    }

    public function getStatsForCourse(int $courseId): array
    {
        $result = $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id) as total_ratings, COALESCE(AVG(cr.rating), 0) as avg_rating')
            ->andWhere('cr.course = :cid')
            ->setParameter('cid', $courseId)
            ->getQuery()
            ->getSingleResult();

        return [
            'total_ratings' => (int) $result['total_ratings'],
            'avg_rating' => round((float) $result['avg_rating'], 1),
        ];
    }

    public function getReviewsForCourse(int $courseId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT cr.*, u.full_name, u.avatar_base64 FROM course_ratings cr
             JOIN users u ON cr.user_id = u.id
             WHERE cr.course_id = ? AND cr.review IS NOT NULL AND cr.review != ""
             ORDER BY cr.created_at DESC LIMIT 20',
            [$courseId]
        );
    }

    public function getUserRatingForCourse(int $userId, int $courseId): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT * FROM course_ratings WHERE user_id = ? AND course_id = ?',
            [$userId, $courseId]
        ) ?: null;
    }

    public function getRatingMapForUser(int $userId): array
    {
        $rows = $this->createQueryBuilder('cr')
            ->select('IDENTITY(cr.course) as course_id, cr.rating')
            ->andWhere('cr.user = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['course_id']] = (int) $r['rating'];
        }
        return $map;
    }

    public function getAverageForCourse(int $courseId): float
    {
        return (float) $this->createQueryBuilder('cr')
            ->select('COALESCE(AVG(cr.rating), 0)')
            ->andWhere('cr.course = :cid')
            ->setParameter('cid', $courseId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
