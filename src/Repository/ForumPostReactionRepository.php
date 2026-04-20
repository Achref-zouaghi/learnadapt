<?php

namespace App\Repository;

use App\Entity\ForumPostReaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumPostReaction>
 */
class ForumPostReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPostReaction::class);
    }

    public function findOneByPostAndUser(int $postId, int $userId): ?ForumPostReaction
    {
        return $this->createQueryBuilder('fpr')
            ->andWhere('fpr.post = :pid')
            ->andWhere('fpr.user = :uid')
            ->setParameter('pid', $postId)
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
