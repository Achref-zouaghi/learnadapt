<?php

namespace App\Repository;

use App\Entity\CourseComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourseComment>
 */
class CourseCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourseComment::class);
    }

    public function findByCourse(int $courseId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT cc.*, u.full_name, u.avatar_base64 FROM course_comments cc
             JOIN users u ON cc.user_id = u.id
             WHERE cc.course_id = ? ORDER BY cc.created_at DESC LIMIT 50',
            [$courseId]
        );
    }
}
