<?php

namespace App\MessageHandler;

use App\Message\SendVerificationEmail;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Does the work described by a SendVerificationEmail message.
 *
 * #[AsMessageHandler] registers this class with the bus automatically.
 * Symfony works out WHICH message it handles from the type-hint on
 * __invoke() — there is no mapping config to keep in sync.
 *
 * Unlike the message, a handler CAN have services: it runs in a live
 * process with a full container.
 */
#[AsMessageHandler]
final readonly class SendVerificationEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private EmailVerifier $emailVerifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendVerificationEmail $message): void
    {
        // Load the user FRESH. This is why the message carries an ID
        // rather than the object — we want the current state, not a
        // snapshot from when the message was queued.
        $user = $this->users->find($message->userId);

        if ($user === null) {
            // The user was deleted between dispatch and processing. That
            // is not an error worth retrying — the work is moot. Return
            // normally so the message is acknowledged and removed.
            $this->logger->info('Skipping verification email: user no longer exists.', [
                'userId' => $message->userId,
            ]);

            return;
        }

        if ($user->isEmailVerified()) {
            // They already clicked a link from an earlier email while this
            // message was waiting. Sending another would be confusing.
            $this->logger->info('Skipping verification email: already verified.', [
                'userId' => $message->userId,
            ]);

            return;
        }

        // If this THROWS, Messenger retries per the retry_strategy above.
        // That is the point of the retries: a temporary SMTP failure
        // should not lose the email.
        $this->emailVerifier->sendVerificationEmail($user);

        $this->logger->info('Verification email sent.', ['userId' => $message->userId]);
    }
}
