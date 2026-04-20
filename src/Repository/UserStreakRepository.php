<?php

namespace App\Repository;

use App\Entity\UserStreak;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserStreak>
 */
class UserStreakRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserStreak::class);
    }

    public function findOneByUser(int $userId): ?array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT * FROM user_streaks WHERE user_id = ?',
            [$userId]
        ) ?: null;
    }
}
