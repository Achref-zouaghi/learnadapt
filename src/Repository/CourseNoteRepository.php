<?php

namespace App\Repository;

use App\Entity\CourseNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseNote>
 */
class CourseNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseNote::class);
    }

    public function findByUserAndCourse(int $userId, int $courseId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT * FROM course_notes WHERE user_id = ? AND course_id = ? ORDER BY updated_at DESC',
            [$userId, $courseId]
        );
    }

    public function findRecentByUser(int $userId, int $limit = 5): array
    {
        return $this->createQueryBuilder('cn')
            ->join('cn.course', 'c')
            ->andWhere('cn.user = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('cn.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('cn')
            ->select('COUNT(cn.id)')
            ->andWhere('cn.user = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
