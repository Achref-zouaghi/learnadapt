<?php

namespace App\Repository;

use App\Entity\CourseFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseFile>
 */
class CourseFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseFile::class);
    }

    public function findByCourse(int $courseId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT * FROM course_files WHERE course_id = ? ORDER BY sort_order ASC, created_at ASC',
            [$courseId]
        );
    }
}
