<?php

namespace App\Repository;

use App\Entity\Course;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Course>
 */
class CourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Course::class);
    }

    public function findFiltered(?string $level, ?string $search, ?int $moduleId): array
    {
        $sql = 'SELECT c.*, m.name as module_name, u.full_name as teacher_name
                FROM courses c
                LEFT JOIN modules m ON c.module_id = m.id
                LEFT JOIN users u ON c.teacher_user_id = u.id
                WHERE 1=1';
        $params = [];

        if ($level && in_array($level, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $sql .= ' AND c.level = ?';
            $params[] = $level;
        }
        if ($moduleId) {
            $sql .= ' AND c.module_id = ?';
            $params[] = $moduleId;
        }
        if ($search) {
            $sql .= ' AND (c.title LIKE ? OR c.description LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY c.created_at DESC';

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);
    }

    public function getLevelCounts(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.level, COUNT(c.id) as cnt')
            ->groupBy('c.level')
            ->getQuery()
            ->getScalarResult();

        $counts = ['total' => 0, 'easy' => 0, 'medium' => 0, 'hard' => 0];
        foreach ($rows as $r) {
            $counts[strtolower($r['level'])] = (int) $r['cnt'];
            $counts['total'] += (int) $r['cnt'];
        }
        return $counts;
    }

    public function getModulesWithCounts(): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT m.id, m.name, COUNT(c.id) as course_count
             FROM App\Entity\Course c
             JOIN c.module m
             GROUP BY m.id, m.name
             ORDER BY m.name'
        )->getResult();
    }

    public function findWithDetails(int $id): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT c.*, m.name as module_name, u.full_name as teacher_name
             FROM courses c
             LEFT JOIN modules m ON c.module_id = m.id
             LEFT JOIN users u ON c.teacher_user_id = u.id
             WHERE c.id = ?',
            [$id]
        ) ?: null;
    }

    public function searchByTopic(string $topic, int $limit = 8): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.module', 'm')
            ->addSelect('m')
            ->andWhere('c.title LIKE :q OR c.description LIKE :q OR m.name LIKE :q')
            ->setParameter('q', '%' . $topic . '%')
            ->orderBy('c.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findNotStartedByUser(int $userId, int $limit = 5): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT c, m FROM App\Entity\Course c
             LEFT JOIN c.module m
             WHERE c.id NOT IN (
                 SELECT IDENTITY(cp.course) FROM App\Entity\CourseProgress cp WHERE cp.user = :uid
             )
             ORDER BY c.created_at DESC'
        )
            ->setParameter('uid', $userId)
            ->setMaxResults($limit)
            ->getResult();
    }
}
