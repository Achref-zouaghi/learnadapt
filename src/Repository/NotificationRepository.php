<?php

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnreadForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :uid')
            ->andWhere('n.is_read = false')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findForUser(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.user = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('n.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
