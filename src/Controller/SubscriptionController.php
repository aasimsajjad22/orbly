<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Payment\StripeGateway;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Current subscription state, read from our local mirror.
     *
     * Deliberately NOT a call to Stripe — that would add 200ms of network
     * to a request the user makes constantly. The mirror is kept current
     * by webhooks, which is the whole reason it exists.
     */
    #[Route('/api/subscription', name: 'api_subscription_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        $subscription = $this->subscriptions->findOneByUser($me);

        if ($subscription === null) {
            return $this->json([
                'isPro' => false,
                'status' => null,
                'postCharacterLimit' => 2000,
            ]);
        }

        return $this->json([
            'subscription' => $subscription,
            'postCharacterLimit' => $subscription->isPro() ? 10000 : 2000,
        ], 200, [], [
            'groups' => ['subscription:read'],
        ]);
    }

    /**
     * Start checkout. Returns a URL for the client to redirect to.
     */
    #[Route('/api/subscription/checkout', name: 'api_subscription_checkout', methods: ['POST'])]
    public function checkout(): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        $subscription = $this->subscriptions->findOneByUser($me);

        // Already paying? Do not let them subscribe twice — that would
        // create a second Stripe subscription and bill them again.
        if ($subscription !== null && $subscription->isPro()) {
            return new JsonResponse(
                ['message' => 'You already have an active subscription.'],
                Response::HTTP_CONFLICT,
            );
        }

        // A Stripe customer can exist without a subscription, so reuse one
        // if we already made it — creating a second would fragment their
        // billing history across two customer records.
        if ($subscription === null) {
            $customerId = $this->stripe->createCustomer(
                (string) $me->getEmail(),
                (string) $me->getDisplayName(),
                (int) $me->getId(),
            );

            $subscription = new Subscription($me, $customerId);

            $this->em->persist($subscription);
            $this->em->flush();
        }

        $url = $this->stripe->createCheckoutSession(
            $subscription->getStripeCustomerId(),
            // In a real app these point at your frontend. With no UI, they
            // are just landing pages the browser ends up on.
            'http://127.0.0.1:8001/subscription/success',
            'http://127.0.0.1:8001/subscription/cancelled',
        );

        // NOTE: we do NOT mark them Pro here. Returning a checkout URL
        // means nothing has been paid — the user may abandon the page.
        // Only the webhook grants access.
        return $this->json(['checkoutUrl' => $url]);
    }

    /**
     * A link to Stripe's hosted billing portal.
     *
     * Cancelling, updating a card, downloading invoices — all handled by
     * Stripe. We build none of it, and we never touch card data.
     */
    #[Route('/api/subscription/portal', name: 'api_subscription_portal', methods: ['POST'])]
    public function portal(): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        $subscription = $this->subscriptions->findOneByUser($me);

        if ($subscription === null) {
            return new JsonResponse(
                ['message' => 'You do not have a subscription.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $url = $this->stripe->createPortalSession(
            $subscription->getStripeCustomerId(),
            'http://127.0.0.1:8001/subscription',
        );

        return $this->json(['portalUrl' => $url]);
    }
}
