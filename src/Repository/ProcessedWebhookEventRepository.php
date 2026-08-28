<?php

namespace App\Repository;

use App\Entity\ProcessedWebhookEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessedWebhookEvent>
 */
class ProcessedWebhookEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedWebhookEvent::class);
    }

    public function hasProcessed(string $stripeEventId): bool
    {
        return $this->findOneBy(['stripeEventId' => $stripeEventId]) !== null;
    }
}
