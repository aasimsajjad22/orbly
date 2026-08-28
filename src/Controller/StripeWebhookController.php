<?php

namespace App\Controller;

use App\Entity\ProcessedWebhookEvent;
use App\Message\ProcessStripeEvent;
use App\Payment\InvalidWebhookSignatureException;
use App\Payment\StripeGateway;
use App\Repository\ProcessedWebhookEventRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly ProcessedWebhookEventRepository $processed,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/stripe/webhook', name: 'api_stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // The RAW body. Do NOT json_decode and re-encode it — the
        // signature is computed over these exact bytes, and any
        // reformatting breaks the comparison.
        $payload = $request->getContent();

        $signature = $request->headers->get('Stripe-Signature', '');

        // ---- 1. VERIFY ----
        // Everything below this point trusts the payload. Without this
        // check, anyone could POST a fake "subscription active" event and
        // grant themselves Pro.
        try {
            $event = $this->stripe->parseWebhook($payload, $signature);
        } catch (InvalidWebhookSignatureException $e) {
            $this->logger->warning('Rejected a Stripe webhook with a bad signature.', [
                'error' => $e->getMessage(),
            ]);

            // 400, flat message. Never explain what was wrong — that
            // would help someone probing the endpoint.
            return new JsonResponse(['error' => 'Invalid signature.'], Response::HTTP_BAD_REQUEST);
        }

        // ---- 2. IDEMPOTENCY ----
        // Stripe retries on any non-2xx and can send duplicates even after
        // a success. Processing "invoice.paid" twice would grant two
        // billing periods for one payment.
        if ($this->processed->hasProcessed($event->id)) {
            $this->logger->info('Ignoring a duplicate Stripe event.', ['eventId' => $event->id]);

            // 200, not an error. Telling Stripe "already handled" stops
            // it retrying. A non-2xx would make it try again forever.
            return new JsonResponse(['status' => 'already_processed']);
        }

        // Record it BEFORE processing. The unique index is the real
        // guarantee: if two duplicate deliveries arrive simultaneously,
        // both pass the check above but only one insert can succeed.
        try {
            $this->em->persist(new ProcessedWebhookEvent($event->id, $event->type));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // The other request won the race. Nothing to do.
            return new JsonResponse(['status' => 'already_processed']);
        }

        // ---- 3. HAND OFF ----
        // Stripe expects a response within seconds and retries on timeout.
        // Doing the work here risks a retry storm, so we queue it and
        // return immediately. This is what Phase 5 was for.
        $this->bus->dispatch(new ProcessStripeEvent(
            $event->id,
            $event->type,
            $event->data,
        ));

        // ---- 4. ACKNOWLEDGE ----
        // 200 means "received", not "processed". That distinction is the
        // point: Stripe stops retrying, and our worker owns the outcome.
        return new JsonResponse(['status' => 'received']);
    }
}
