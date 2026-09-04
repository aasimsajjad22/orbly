<?php

namespace App\Controller\Web;

use App\Entity\Subscription;
use App\Entity\User;
use App\Payment\StripeGateway;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SubscriptionWebController extends AbstractController
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/subscription', name: 'app_subscription', methods: ['GET'])]
    public function show(): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        return $this->render('subscription/show.html.twig', [
            'subscription' => $this->subscriptions->findOneByUserId((int) $me->getId()),
        ]);
    }

    /**
     * Redirects the browser to Stripe's hosted checkout.
     *
     * A POST rather than a GET, because it creates a Stripe session — a
     * side effect. A GET link would fire on every prefetch and browser
     * preview, creating sessions nobody asked for.
     */
    #[Route('/subscription/checkout', name: 'app_subscription_checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        if (!$this->isCsrfTokenValid('checkout', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('app_subscription');
        }

        $subscription = $this->subscriptions->findOneByUserId((int) $me->getId());

        if ($subscription !== null && $subscription->isPro()) {
            $this->addFlash('error', 'You already have an active subscription.');

            return $this->redirectToRoute('app_subscription');
        }

        // Reuse the Stripe customer if one exists, so their billing
        // history stays on a single record.
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
            $this->generateUrl('app_subscription_success', [], 0),
            $this->generateUrl('app_subscription', [], 0),
        );

        // IMPORTANT: Turbo intercepts normal redirects and tries to swap
        // the HTML — which fails for an external domain. This header
        // tells Turbo to do a real browser navigation instead.
        return $this->redirect($url);
    }

    #[Route('/subscription/portal', name: 'app_subscription_portal', methods: ['POST'])]
    public function portal(Request $request): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        if (!$this->isCsrfTokenValid('portal', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('app_subscription');
        }

        $subscription = $this->subscriptions->findOneByUserId((int) $me->getId());

        if ($subscription === null) {
            return $this->redirectToRoute('app_subscription');
        }

        return $this->redirect($this->stripe->createPortalSession(
            $subscription->getStripeCustomerId(),
            $this->generateUrl('app_subscription', [], 0),
        ));
    }

    /**
     * Where Stripe sends the browser after a successful checkout.
     *
     * Deliberately does NOT grant anything. The webhook does that, and it
     * may not have arrived yet — which is why the page says "processing"
     * rather than "you're Pro".
     */
    #[Route('/subscription/success', name: 'app_subscription_success', methods: ['GET'])]
    public function success(): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        return $this->render('subscription/success.html.twig', [
            'subscription' => $this->subscriptions->findOneByUserId((int) $me->getId()),
        ]);
    }
}
