<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findOneByUser(User $user): ?Subscription
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Webhooks identify the subscription by Stripe's id, not ours.
     */
    public function findOneByStripeSubscriptionId(string $id): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $id]);
    }

    /**
     * Some events (checkout.session.completed) carry the customer id but
     * not yet a subscription id, so we need both lookups.
     */
    public function findOneByStripeCustomerId(string $id): ?Subscription
    {
        return $this->findOneBy(['stripeCustomerId' => $id]);
    }

    /**
     * Look up by user ID rather than the User object.
     *
     * findOneBy(['user' => $userObject]) fails when the object has been
     * detached — after an EntityManager clear(), for instance. An integer
     * id has no such problem.
     */
    public function findOneByUserId(int $userId): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
