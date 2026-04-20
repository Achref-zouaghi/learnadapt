<?php

namespace App\Repository;

use App\Entity\FeedbackReaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeedbackReaction>
 */
class FeedbackReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedbackReaction::class);
    }

    public function findOneByFeedbackAndUser(int $feedbackId, int $userId): ?FeedbackReaction
    {
        return $this->createQueryBuilder('fr')
            ->andWhere('fr.feedback = :fid')
            ->andWhere('fr.user = :uid')
            ->setParameter('fid', $feedbackId)
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByType(int $feedbackId, string $type): int
    {
        return (int) $this->createQueryBuilder('fr')
            ->select('COUNT(fr.id)')
            ->andWhere('fr.feedback = :fid')
            ->andWhere('fr.type = :type')
            ->setParameter('fid', $feedbackId)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
