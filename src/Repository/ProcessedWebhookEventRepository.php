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

    /**
     * How many records are older than the cutoff.
     */
    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.processedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Delete one batch of old records.
     *
     * Batched rather than one big DELETE. A single statement over
     * millions of rows takes a long lock, blocks other writes, and can
     * time out — leaving nothing deleted at all. Small batches let other
     * queries interleave.
     *
     * Postgres has no LIMIT on DELETE, so we select the ids first and
     * delete by primary key.
     *
     * @return int rows deleted in this batch
     */
    public function deleteBatchOlderThan(\DateTimeImmutable $cutoff, int $batchSize): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $ids = $conn->executeQuery(
            'SELECT id FROM processed_webhook_events WHERE processed_at < :cutoff LIMIT :limit',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s'), 'limit' => $batchSize],
            ['limit' => \PDO::PARAM_INT],
        )->fetchFirstColumn();

        if ($ids === []) {
            return 0;
        }

        return (int) $conn->executeStatement(
            'DELETE FROM processed_webhook_events WHERE id IN (:ids)',
            ['ids' => $ids],
            // ARRAY_INT tells DBAL to expand the array into a list of
            // placeholders. Without it, the array is passed as a single
            // parameter and the query fails.
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }
}
