<?php

namespace App\Repository;

use App\Entity\FriendRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FriendRequest>
 */
class FriendRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FriendRequest::class);
    }

    public function findPendingForUser(int $userId): array
    {
        return $this->createQueryBuilder('fr')
            ->join('fr.sender', 's')
            ->andWhere('fr.receiver = :uid')
            ->andWhere('fr.status = :status')
            ->setParameter('uid', $userId)
            ->setParameter('status', 'pending')
            ->orderBy('fr.created_at', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPendingForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('fr')
            ->select('COUNT(fr.id)')
            ->andWhere('fr.receiver = :uid')
            ->andWhere('fr.status = :status')
            ->setParameter('uid', $userId)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findAcceptedFriends(int $userId): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT u FROM App\Entity\User u
             WHERE u.id IN (
                 SELECT IDENTITY(fr.receiver) FROM App\Entity\FriendRequest fr
                 WHERE fr.sender = :uid AND fr.status = :status
             )
             OR u.id IN (
                 SELECT IDENTITY(fr2.sender) FROM App\Entity\FriendRequest fr2
                 WHERE fr2.receiver = :uid AND fr2.status = :status
             )'
        )
            ->setParameter('uid', $userId)
            ->setParameter('status', 'accepted')
            ->getResult();
    }

    public function existsBetween(int $senderId, int $receiverId): bool
    {
        $count = (int) $this->createQueryBuilder('fr')
            ->select('COUNT(fr.id)')
            ->andWhere('(fr.sender = :sid AND fr.receiver = :rid) OR (fr.sender = :rid AND fr.receiver = :sid)')
            ->setParameter('sid', $senderId)
            ->setParameter('rid', $receiverId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
