<?php

namespace App\Repository;

use App\Entity\CourseBookmark;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseBookmark>
 */
class CourseBookmarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseBookmark::class);
    }

    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('cb')
            ->andWhere('cb.user = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('cb.created_at', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getBookmarkedCourseIds(int $userId): array
    {
        $rows = $this->createQueryBuilder('cb')
            ->select('IDENTITY(cb.course) as course_id')
            ->andWhere('cb.user = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'course_id');
    }

    public function findOneByUserAndCourse(int $userId, int $courseId): ?CourseBookmark
    {
        return $this->createQueryBuilder('cb')
            ->andWhere('cb.user = :uid')
            ->andWhere('cb.course = :cid')
            ->setParameter('uid', $userId)
            ->setParameter('cid', $courseId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
