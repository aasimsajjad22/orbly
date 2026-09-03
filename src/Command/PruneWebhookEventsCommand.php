<?php

namespace App\Command;

use App\Repository\ProcessedWebhookEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:webhooks:prune',
    description: 'Delete processed Stripe webhook event records older than a cutoff.',
)]
final class PruneWebhookEventsCommand extends Command
{
    public function __construct(
        private readonly ProcessedWebhookEventRepository $events,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete records older than this many days.',
                // 90 days. Stripe stops retrying an event long before
                // that, so anything older can no longer arrive as a
                // duplicate — the idempotency guarantee is unaffected.
                90,
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without deleting.')
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Rows to delete per batch.',
                1000,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = (int) $input->getOption('batch-size');

        // Guard against a fat-fingered --days=0, which would delete
        // everything including events Stripe might still retry.
        if ($days < 30) {
            $io->error('Refusing to prune records newer than 30 days — Stripe may still retry them.');

            return Command::FAILURE;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        $io->title('Webhook event pruning');
        $io->text(sprintf('Cutoff: %s', $cutoff->format('Y-m-d H:i')));

        $total = $this->events->countOlderThan($cutoff);

        if ($total === 0) {
            $io->success('Nothing to prune.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d record(s) eligible for deletion.', $total));

        if ($dryRun) {
            $io->note('Dry run — nothing was deleted.');

            return Command::SUCCESS;
        }

        $deleted = 0;
        $progress = $io->createProgressBar($total);

        // Loop until a batch comes back empty. Deleting in chunks keeps
        // each lock short so other queries are not blocked.
        while (true) {
            $batch = $this->events->deleteBatchOlderThan($cutoff, $batchSize);

            if ($batch === 0) {
                break;
            }

            $deleted += $batch;
            $progress->advance($batch);

            // Breathe. Gives other connections a window between batches
            // — the difference between a maintenance job and an outage.
            usleep(100_000);   // 100ms
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf('Deleted %d record(s).', $deleted));

        return Command::SUCCESS;
    }
}
