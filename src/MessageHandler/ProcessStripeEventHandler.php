<?php

namespace App\MessageHandler;

use App\Enum\SubscriptionStatus;
use App\Message\ProcessStripeEvent;
use App\Payment\SubscriptionPayloadReader;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\SubscriptionActivated;
use App\Message\SubscriptionCancelled;
use App\Message\SubscriptionPaymentFailed;

/**
 * Applies a verified Stripe event to our local subscription mirror.
 */
#[AsMessageHandler]
final readonly class ProcessStripeEventHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private SubscriptionPayloadReader $reader,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ProcessStripeEvent $message): void
    {
        // A match on event type. Anything not listed is ignored — and
        // ignoring is a RETURN, not a throw: Stripe sends many event
        // types we did not subscribe to conceptually, and retrying them
        // would be pointless.
        match ($message->eventType) {
            'checkout.session.completed' => $this->onCheckoutCompleted($message->data),
            'customer.subscription.updated',
            'customer.subscription.created' => $this->onSubscriptionChanged($message->data),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($message->data),
            'invoice.payment_failed' => $this->onPaymentFailed($message->data),
            default => $this->logger->debug('Ignoring an unhandled Stripe event type.', [
                'type' => $message->eventType,
            ]),
        };
    }

    /**
     * The user finished paying on Stripe's hosted page.
     *
     * The session payload carries the customer and the newly created
     * subscription id, which is how we link the two.
     */
    private function onCheckoutCompleted(array $data): void
    {
        $customerId = $data['customer'] ?? null;
        $subscriptionId = $data['subscription'] ?? null;

        if ($customerId === null || $subscriptionId === null) {
            // A checkout session in 'payment' mode has no subscription.
            // Not an error for us — just not our concern.
            return;
        }

        $subscription = $this->subscriptions->findOneByStripeCustomerId($customerId);

        if ($subscription === null) {
            // We have no row for this customer. Should not happen, since
            // we create the row before starting checkout — but if it does,
            // retrying will not conjure one, so do not waste attempts.
            throw new UnrecoverableMessageHandlingException(
                sprintf('No local subscription for Stripe customer %s.', $customerId)
            );
        }

        // We know the subscription EXISTS but this payload does not carry
        // its status or period. The customer.subscription.created event
        // does, and it arrives around the same time — possibly BEFORE
        // this one. So we only record the id here and let the
        // subscription events carry the state.
        $subscription->syncFromStripe(
            $subscriptionId,
            $subscription->getStatus(),          // unchanged
            $subscription->getCurrentPeriodEnd(), // unchanged
            $subscription->isCancelAtPeriodEnd(), // unchanged
        );

        $this->em->flush();

        $this->logger->info('Linked a Stripe subscription after checkout.', [
            'subscriptionId' => $subscriptionId,
        ]);
    }

    /**
     * The subscription's state changed — created, renewed, cancelled at
     * period end, card updated, plan changed. One handler for all of it,
     * because Stripe sends the WHOLE subscription object every time.
     *
     * That is why syncFromStripe() takes everything at once: applying the
     * full payload means out-of-order delivery is much less dangerous.
     */
    private function onSubscriptionChanged(array $data): void
    {
        $subscription = $this->findLocalSubscription($data);

        if ($subscription === null) {
            return;
        }

        // Capture the old status BEFORE syncing, so we can tell an
        // activation from a routine update. Without this we would email
        // on every subscription.updated event, which fires for card
        // changes and plan tweaks too.
        $wasActive = $subscription->isPro();
        $hadSubscriptionId = $subscription->getStripeSubscriptionId() !== null;

        $newStatus = $this->reader->readStatus($data);

        $subscription->syncFromStripe(
            $data['id'],
            $this->reader->readStatus($data),
            $this->reader->readCurrentPeriodEnd($data),
            $this->reader->readCancelAtPeriodEnd($data),
        );

        $this->em->flush();

        // Dispatch AFTER the flush. The email handler reloads the
        // subscription, and it must see the new state.
        $this->dispatchEmailFor($subscription, $wasActive, $hadSubscriptionId, $data);


        $this->logger->info('Synced subscription state from Stripe.', [
            'subscriptionId' => $data['id'],
            'status' => $data['status'],
        ]);
    }

    /**
     * Decide which email, if any, this state change warrants.
     *
     * Kept separate from the sync so the branching is readable, and so
     * the sync itself has no opinion about email.
     */
    private function dispatchEmailFor(
        \App\Entity\Subscription $subscription,
        bool $wasActive,
        bool $hadSubscriptionId,
        array $data,
    ): void {
        $userId = (int) $subscription->getUser()->getId();

        // Became active having not been. Covers both the first payment
        // and recovery after a failed one.
        if (!$wasActive && $subscription->isPro()) {
            $this->bus->dispatch(new SubscriptionActivated(
                $userId,
                // No prior subscription id means this is their first.
                isFirstPayment: !$hadSubscriptionId,
            ));

            return;
        }

        // They cancelled — status is still active, but the flag is set.
        // Only email on the TRANSITION, not on every later update, or
        // they get the same email each time Stripe touches the record.
        if ($subscription->isCancelAtPeriodEnd() && !($data['_alreadyCancelled'] ?? false)) {
            $this->bus->dispatch(new SubscriptionCancelled(
                $userId,
                $subscription->getCurrentPeriodEnd()?->format('j F Y'),
            ));
        }
    }

    private function onSubscriptionDeleted(array $data): void
    {
        $subscription = $this->findLocalSubscription($data);

        if ($subscription === null) {
            return;
        }

        // Explicitly Canceled rather than trusting the payload's status —
        // the deleted event is unambiguous about what happened.
        $subscription->syncFromStripe(
            $data['id'],
            SubscriptionStatus::Canceled,
            $this->reader->readCurrentPeriodEnd($data),
            false,   // nothing left to cancel
        );

        $this->em->flush();

        $this->logger->info('Subscription cancelled.', ['subscriptionId' => $data['id']]);
    }

    /**
     * A renewal charge failed.
     *
     * We do NOT change the status here. Stripe will send a
     * customer.subscription.updated with status past_due (and later
     * canceled or unpaid) once its dunning process decides. Acting on the
     * invoice event too would mean two sources fighting over the same
     * field.
     *
     * Phase 7 hangs a "your payment failed" email off this.
     */
    private function onPaymentFailed(array $data): void
    {
        $customerId = $data['customer'] ?? null;

        if ($customerId === null) {
            return;
        }

        $subscription = $this->subscriptions->findOneByStripeCustomerId($customerId);

        if ($subscription === null) {
            return;
        }

        // Still no status change here — Stripe's dunning process decides
        // that and sends a subscription.updated when it does. We only
        // email, so the user can fix their card before it escalates.
        $this->bus->dispatch(new SubscriptionPaymentFailed(
            (int) $subscription->getUser()->getId()
        ));

        $this->logger->warning('A Stripe invoice payment failed.', [
            'customer' => $customerId,
        ]);
    }

    /**
     * Find our row for a Stripe subscription payload.
     *
     * Tries the subscription id first, then the customer id — because the
     * very first subscription event may arrive before checkout.session
     * has stored the subscription id on our side.
     */
    private function findLocalSubscription(array $data): ?\App\Entity\Subscription
    {
        $subscription = $this->subscriptions->findOneByStripeSubscriptionId($data['id']);

        if ($subscription !== null) {
            return $subscription;
        }

        $customerId = $data['customer'] ?? null;

        if ($customerId !== null) {
            $subscription = $this->subscriptions->findOneByStripeCustomerId($customerId);
        }

        if ($subscription === null) {
            $this->logger->warning('Received a Stripe event for an unknown subscription.', [
                'subscriptionId' => $data['id'] ?? null,
                'customer' => $customerId ?? null,
            ]);
        }

        return $subscription;
    }
}
