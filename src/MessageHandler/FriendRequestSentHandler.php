<?php

namespace App\MessageHandler;

use App\Enum\FriendRequestStatus;
use App\Message\FriendRequestSent;
use App\Repository\FriendRequestRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Emails the recipient that someone wants to connect.
 *
 * One of potentially several handlers for this event — a push
 * notification handler would sit alongside it, and neither would know
 * about the other.
 */
#[AsMessageHandler]
final readonly class FriendRequestSentHandler
{
    public function __construct(
        private FriendRequestRepository $requests,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(FriendRequestSent $message): void
    {
        $request = $this->requests->find($message->friendRequestId);

        // The request was deleted between dispatch and processing.
        // Nothing to do, and retrying will not change that — return
        // normally so the message is acknowledged.
        if ($request === null) {
            $this->logger->info('Friend request notification skipped: request no longer exists.', [
                'friendRequestId' => $message->friendRequestId,
            ]);

            return;
        }

        // THE reason we reload rather than carrying data in the message.
        //
        // Between dispatch and processing, the recipient may have already
        // accepted, or the sender may have cancelled. Emailing "X wants to
        // connect" after they are already friends is confusing, and after
        // a cancellation it is misleading.
        if ($request->getStatus() !== FriendRequestStatus::Pending) {
            $this->logger->info('Friend request notification skipped: no longer pending.', [
                'friendRequestId' => $message->friendRequestId,
                'status' => $request->getStatus()->value,
            ]);

            return;
        }

        $sender = $request->getSender();
        $recipient = $request->getRecipient();

        // Do not email unverified addresses. We have no proof they belong
        // to the recipient, and sending to unconfirmed addresses is how
        // you damage your sending reputation.
        if (!$recipient->isEmailVerified()) {
            $this->logger->info('Friend request notification skipped: recipient email unverified.', [
                'friendRequestId' => $message->friendRequestId,
            ]);

            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@orbly.test', 'Orbly'))
            ->to((string) $recipient->getEmail())
            ->subject(sprintf('%s sent you a friend request', $sender->getDisplayName()))
            ->htmlTemplate('email/friend_request.html.twig')
            ->textTemplate('email/friend_request.txt.twig')
            ->context([
                'recipientName' => $recipient->getDisplayName(),
                'senderName' => $sender->getDisplayName(),
                // ABSOLUTE_URL, not a path. There is no incoming request
                // in a worker to infer the host from — this relies on the
                // router.default_uri set back in Phase 2c.
                'friendsUrl' => $this->urlGenerator->generate(
                    'app_friends',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
            ]);

        // Throwing here means Messenger retries — correct for a transient
        // SMTP failure.
        $this->mailer->send($email);

        $this->logger->info('Friend request notification sent.', [
            'friendRequestId' => $message->friendRequestId,
        ]);
    }
}
