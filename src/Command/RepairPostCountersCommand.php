<?php

namespace App\Command;

use App\Repository\PostRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconciles the denormalised like/comment counters against the real rows.
 *
 * #[AsCommand] registers this with the console — the same tag-and-discover
 * pattern as #[AsMessageHandler] and #[AsLiveComponent].
 */
#[AsCommand(
    name: 'app:posts:repair-counters',
    description: 'Find and fix posts whose like/comment counters have drifted.',
)]
final class RepairPostCountersCommand extends Command
{
    public function __construct(
        private readonly PostRepository $posts,
    ) {
        // Command has its own constructor that must run.
        parent::__construct();
    }

    /**
     * Declare the options. Symfony parses them and validates the input
     * before execute() runs.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                // VALUE_NONE = a flag, present or absent, no value.
                InputOption::VALUE_NONE,
                'Report what would change without writing anything.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of posts to check.',
                1000,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle wraps the input/output with helpers — titles,
        // tables, progress bars, and consistent colours.
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $io->title('Post counter reconciliation');

        if ($dryRun) {
            // Say so loudly. A user who thinks they fixed something and
            // did not is worse off than one who knows nothing happened.
            $io->note('Dry run — no changes will be written.');
        }

        $drifted = $this->posts->findPostsWithDriftedCounters($limit);

        if ($drifted === []) {
            $io->success('All counters match. Nothing to repair.');

            // 0. Cron and Kubernetes read this to decide whether the job
            // succeeded — returning the wrong code means failures go
            // unnoticed forever.
            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d post(s) have drifted counters.', count($drifted)));

        // Show the damage before touching anything.
        $io->table(
            ['Post', 'Likes (stored)', 'Likes (real)', 'Comments (stored)', 'Comments (real)'],
            array_map(
                static fn (array $row) => [
                    $row['id'],
                    $row['like_count'],
                    $row['real_likes'],
                    $row['comment_count'],
                    $row['real_comments'],
                ],
                $drifted
            )
        );

        if ($dryRun) {
            $io->note('Run without --dry-run to apply these fixes.');

            return Command::SUCCESS;
        }

        // progressIterate wraps a loop with a progress bar. Pointless for
        // five rows, useful when a production run finds thousands.
        foreach ($io->progressIterate($drifted) as $row) {
            $this->posts->repairCounters((int) $row['id']);
        }

        $io->success(sprintf('Repaired %d post(s).', count($drifted)));

        return Command::SUCCESS;
    }
}
