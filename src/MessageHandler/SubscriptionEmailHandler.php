<?php

namespace App\MessageHandler;

use App\Message\SubscriptionActivated;
use App\Message\SubscriptionCancelled;
use App\Message\SubscriptionPaymentFailed;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Subscription-related emails.
 *
 * ONE class with three #[AsMessageHandler] methods. The attribute can go
 * on a method rather than the class when a service handles several
 * message types — the type-hint on each method decides which it handles.
 */
final readonly class SubscriptionEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function onActivated(SubscriptionActivated $message): void
    {
        $user = $this->users->find($message->userId);

        if ($user === null) {
            return;
        }

        // Renewals get a quieter email than a first payment — nobody
        // wants "Welcome to Pro!" every month.
        $subject = $message->isFirstPayment
            ? 'Welcome to Orbly Pro'
            : 'Your Orbly Pro subscription has renewed';

        $this->send(
            $user->getEmail(),
            $subject,
            'email/subscription_activated',
            [
                'displayName' => $user->getDisplayName(),
                'isFirstPayment' => $message->isFirstPayment,
                'subscriptionUrl' => $this->absoluteUrl('app_subscription'),
            ],
        );
    }

    #[AsMessageHandler]
    public function onPaymentFailed(SubscriptionPaymentFailed $message): void
    {
        $user = $this->users->find($message->userId);

        if ($user === null) {
            return;
        }

        // The most important email in this set. The user has minutes or
        // days to fix a card before Stripe gives up, and they will not
        // notice on their own.
        $this->send(
            $user->getEmail(),
            'Action needed: your Orbly payment failed',
            'email/subscription_payment_failed',
            [
                'displayName' => $user->getDisplayName(),
                'subscriptionUrl' => $this->absoluteUrl('app_subscription'),
            ],
        );
    }

    #[AsMessageHandler]
    public function onCancelled(SubscriptionCancelled $message): void
    {
        $user = $this->users->find($message->userId);

        if ($user === null) {
            return;
        }

        $this->send(
            $user->getEmail(),
            'Your Orbly Pro subscription is ending',
            'email/subscription_cancelled',
            [
                'displayName' => $user->getDisplayName(),
                'accessUntil' => $message->accessUntil,
                'subscriptionUrl' => $this->absoluteUrl('app_subscription'),
            ],
        );
    }

    /**
     * Shared send. Both templates are named by convention:
     * <template>.html.twig and <template>.txt.twig.
     */
    private function send(string $to, string $subject, string $template, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@orbly.test', 'Orbly'))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template.'.html.twig')
            ->textTemplate($template.'.txt.twig')
            ->context($context);

        $this->mailer->send($email);

        $this->logger->info('Subscription email sent.', ['template' => $template, 'to' => $to]);
    }

    private function absoluteUrl(string $route): string
    {
        // ABSOLUTE_URL because there is no incoming request in a worker.
        // Relies on router.default_uri from Phase 2c.
        return $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
