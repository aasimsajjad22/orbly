<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A quick operational snapshot.
 *
 * Raw SQL through the Connection rather than repositories: this is
 * reporting, not domain logic, and a dozen COUNT queries through the ORM
 * would be slower and no clearer.
 */
#[AsCommand(
    name: 'app:stats',
    description: 'Show a snapshot of application activity.',
)]
final class StatsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Orbly');

        $io->section('Users');
        $io->definitionList(
            ['Total' => $this->count('SELECT COUNT(*) FROM users')],
            ['Verified' => $this->count('SELECT COUNT(*) FROM users WHERE email_verified = true')],
            ['Via Google' => $this->count('SELECT COUNT(*) FROM users WHERE google_id IS NOT NULL')],
            ['Joined this week' => $this->count(
                "SELECT COUNT(*) FROM users WHERE created_at > NOW() - INTERVAL '7 days'"
            )],
        );

        $io->section('Content');
        $io->definitionList(
            ['Posts' => $this->count('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL')],
            ['Deleted posts' => $this->count('SELECT COUNT(*) FROM posts WHERE deleted_at IS NOT NULL')],
            ['Comments' => $this->count('SELECT COUNT(*) FROM comments')],
            ['Likes' => $this->count('SELECT COUNT(*) FROM post_likes')],
        );

        $io->section('Social graph');
        $io->definitionList(
        // Divided by two: every friendship is stored as two mirror
        // rows, so the raw count is double the real number.
            ['Friendships' => (int) ($this->count('SELECT COUNT(*) FROM friendships') / 2)],
            ['Pending requests' => $this->count(
                "SELECT COUNT(*) FROM friend_requests WHERE status = 'pending'"
            )],
            ['Blocks' => $this->count('SELECT COUNT(*) FROM blocks')],
        );

        $io->section('Subscriptions');
        $io->definitionList(
            ['Active' => $this->count(
                "SELECT COUNT(*) FROM subscriptions WHERE status IN ('active', 'trialing')"
            )],
            ['Past due' => $this->count("SELECT COUNT(*) FROM subscriptions WHERE status = 'past_due'")],
            ['Cancelling' => $this->count(
                'SELECT COUNT(*) FROM subscriptions WHERE cancel_at_period_end = true'
            )],
        );

        // A health signal worth surfacing: drifted counters mean a
        // transaction failed somewhere.
        $drifted = $this->count(<<<'SQL'
            SELECT COUNT(*) FROM posts p
            WHERE p.like_count <> (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id)
               OR p.comment_count <> (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id)
            SQL);

        if ($drifted > 0) {
            $io->warning(sprintf(
                '%d post(s) have drifted counters. Run app:posts:repair-counters.',
                $drifted
            ));
        }

        return Command::SUCCESS;
    }

    private function count(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }
}
