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
}
