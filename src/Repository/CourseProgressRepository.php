<?php

namespace App\Repository;

use App\Entity\CourseProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseProgress>
 */
class CourseProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseProgress::class);
    }

    public function findOneByUserAndCourse(int $userId, int $courseId): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT * FROM course_progress WHERE user_id = ? AND course_id = ?',
            [$userId, $courseId]
        ) ?: null;
    }

    public function getProgressMapForUser(int $userId): array
    {
        $rows = $this->createQueryBuilder('cp')
            ->select('IDENTITY(cp.course) as course_id, cp.progress_percent, cp.xp_earned')
            ->andWhere('cp.user = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['course_id']] = [
                'progress_percent' => (int) $r['progress_percent'],
                'xp_earned' => (int) $r['xp_earned'],
            ];
        }
        return $map;
    }

    public function getTotalXpForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COALESCE(SUM(cp.xp_earned), 0)')
            ->andWhere('cp.user = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COUNT(cp.id)')
            ->andWhere('cp.user = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCompletedByUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COUNT(cp.id)')
            ->andWhere('cp.user = :uid')
            ->andWhere('cp.progress_percent = 100')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getAverageProgressForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COALESCE(AVG(cp.progress_percent), 0)')
            ->andWhere('cp.user = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByUserWithCourse(int $userId, int $limit = 10): array
    {
        return $this->createQueryBuilder('cp')
            ->join('cp.course', 'c')
            ->andWhere('cp.user = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('cp.last_accessed', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findInProgressByUser(int $userId, int $limit = 3): array
    {
        return $this->createQueryBuilder('cp')
            ->join('cp.course', 'c')
            ->andWhere('cp.user = :uid')
            ->andWhere('cp.progress_percent < 100')
            ->setParameter('uid', $userId)
            ->orderBy('cp.progress_percent', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getLeaderboard(int $courseId, int $limit = 10): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT cp.xp_earned, cp.progress_percent, u.full_name, u.avatar_base64
             FROM course_progress cp
             JOIN users u ON cp.user_id = u.id
             WHERE cp.course_id = ? AND cp.xp_earned > 0
             ORDER BY cp.xp_earned DESC LIMIT ' . (int) $limit,
            [$courseId]
        );
    }

    public function getUserRank(int $userId, int $userXp): int
    {
        $count = (int) $this->getEntityManager()->createQuery(
            'SELECT COUNT(DISTINCT cp.user)
             FROM App\Entity\CourseProgress cp
             WHERE cp.user != :uid
             GROUP BY cp.user
             HAVING SUM(cp.xp_earned) > :xp'
        )
            ->setParameter('uid', $userId)
            ->setParameter('xp', $userXp)
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_SINGLE_SCALAR);

        return ($count ?: 0) + 1;
    }
}
