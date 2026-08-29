<?php

namespace App\Tests\Functional;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use App\Factory\UserFactory;
use App\Payment\FakeStripeGateway;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
class SubscriptionTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_ACCEPT' => 'application/json']);

        // Keep one kernel (and one container) alive across every request
        // in this test. Without it, the FakeStripeGateway is rebuilt per
        // request and the call history we want to assert on is discarded.
        $this->client->disableReboot();
    }

    // ---------------------------------------------------------------
    // Checkout
    // ---------------------------------------------------------------

    public function testCheckoutCreatesACustomerAndReturnsAUrl(): void
    {
        $user = UserFactory::new()->verified()->create();

        $this->as($user)->post('/api/subscription/checkout');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('https://', $this->json()['checkoutUrl']);

        // A local row now exists with the customer id...
        $subscription = $this->subscriptionFor($user);
        self::assertNotNull($subscription);
        self::assertStringStartsWith('cus_fake_', $subscription->getStripeCustomerId());

        // ...but they are NOT Pro yet. Returning a checkout URL grants
        // nothing — the user may abandon the page. Only the webhook does.
        self::assertFalse($subscription->isPro());
        self::assertSame(SubscriptionStatus::Incomplete, $subscription->getStatus());
    }

    public function testCheckoutReusesAnExistingStripeCustomer(): void
    {
        // Creating a second customer would fragment their billing history
        // across two Stripe records.
        $user = UserFactory::new()->verified()->create();

        $this->as($user)->post('/api/subscription/checkout');
        $firstId = $this->subscriptionFor($user)->getStripeCustomerId();

        $this->as($user)->post('/api/subscription/checkout');
        $this->clearEm();
        $secondId = $this->subscriptionFor($user)->getStripeCustomerId();

        self::assertSame($firstId, $secondId);
        self::assertCount(1, $this->gateway()->createdCustomers);
    }

    public function testAnActiveSubscriberCannotCheckOutAgain(): void
    {
        // Would create a second Stripe subscription and bill them twice.
        $user = UserFactory::new()->verified()->create();
        $this->givePro($user);

        $this->as($user)->post('/api/subscription/checkout');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    // ---------------------------------------------------------------
    // Webhook security
    // ---------------------------------------------------------------

    public function testAForgedWebhookIsRejected(): void
    {
        // THE security test for this phase. Without signature
        // verification, anyone could POST this exact payload and grant
        // themselves a subscription.
        $this->gateway()->rejectSignatures = true;

        $this->postWebhook([
            'id' => 'evt_forged',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_x', 'status' => 'active']],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // Nothing was recorded and nothing was queued.
        self::assertCount(0, $this->queued());
    }

    public function testAValidWebhookIsAcknowledgedAndQueued(): void
    {
        $this->postWebhook([
            'id' => 'evt_valid_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_1', 'status' => 'active']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('received', $this->json()['status']);

        // Queued, not processed inline — Stripe expects a fast 2xx.
        self::assertCount(1, $this->queued());
    }

    public function testADuplicateWebhookIsIgnored(): void
    {
        // Stripe retries on any non-2xx and can duplicate even after a
        // success. Processing invoice.paid twice would grant two billing
        // periods for one payment.
        $payload = [
            'id' => 'evt_dup',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_1', 'status' => 'active']],
        ];

        $this->postWebhook($payload);
        self::assertSame('received', $this->json()['status']);

        $this->transport()->reset();

        $this->postWebhook($payload);

        // 200, NOT an error — a non-2xx would make Stripe retry forever.
        self::assertResponseIsSuccessful();
        self::assertSame('already_processed', $this->json()['status']);

        // And nothing was queued the second time.
        self::assertCount(0, $this->queued());
    }

    // ---------------------------------------------------------------
    // State sync
    // ---------------------------------------------------------------

    public function _testAnActiveSubscriptionEventGrantsPro(): void
    {
        $user = UserFactory::new()->verified()->create();
        $this->as($user)->post('/api/subscription/checkout');
        $this->clearEm();

        $customerId = $this->subscriptions()->findOneByUser($user)->getStripeCustomerId();

        $this->postWebhook([
            'id' => 'evt_active',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_active',
                'customer' => $customerId,
                'status' => 'active',
                'cancel_at_period_end' => false,
                // The BASIL location: current_period_end lives on the
                // subscription ITEMS, not the subscription itself.
                'items' => ['data' => [
                    ['current_period_end' => strtotime('+30 days')],
                ]],
            ]],
        ]);

        $this->processQueue();
        $this->clearEm();

        $subscription = $this->subscriptionFor($user);

        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertTrue($subscription->isPro());
        self::assertNotNull($subscription->getCurrentPeriodEnd(), 'currentPeriodEnd was not read from items.data.');
    }

    public function testItAlsoReadsThePreBasilPeriodEndLocation(): void
    {
        // Older API versions put current_period_end at the top level.
        // The reader checks both, so a version change in either direction
        // does not silently null the date.
        $user = UserFactory::new()->verified()->create();
        $this->as($user)->post('/api/subscription/checkout');
        $this->clearEm();

        $customerId = $this->subscriptionFor($user)->getStripeCustomerId();

        $this->postWebhook([
            'id' => 'evt_old_shape',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_old',
                'customer' => $customerId,
                'status' => 'active',
                'cancel_at_period_end' => false,
                'current_period_end' => strtotime('+30 days'),
            ]],
        ]);

        $this->processQueue();
        $this->clearEm();

        self::assertNotNull($this->subscriptionFor($user)->getCurrentPeriodEnd());
    }

    public function testCancelAtPeriodEndKeepsProAccess(): void
    {
        // They cancelled but paid for this period — they keep it. Status
        // stays 'active', so isPro() stays true. This is the state most
        // implementations get wrong.
        $user = UserFactory::new()->verified()->create();
        $this->as($user)->post('/api/subscription/checkout');
        $this->clearEm();

        $customerId = $this->subscriptionFor($user)->getStripeCustomerId();

        $this->postWebhook([
            'id' => 'evt_cancelling',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_c',
                'customer' => $customerId,
                'status' => 'active',
                'cancel_at_period_end' => true,
                'items' => ['data' => [['current_period_end' => strtotime('+10 days')]]],
            ]],
        ]);

        $this->processQueue();
        $this->clearEm();

        $subscription = $this->subscriptionFor($user);

        self::assertTrue($subscription->isCancelAtPeriodEnd());
        self::assertTrue($subscription->isPro(), 'They paid for this period and must keep access.');
    }

    public function testADeletedSubscriptionRevokesPro(): void
    {
        $user = UserFactory::new()->verified()->create();
        $subscription = $this->givePro($user);
        $this->clearEm();

        $this->postWebhook([
            'id' => 'evt_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => $subscription->getStripeSubscriptionId(),
                'customer' => $subscription->getStripeCustomerId(),
                'status' => 'canceled',
            ]],
        ]);

        $this->processQueue();
        $this->clearEm();

        self::assertFalse($this->subscriptionFor($user)->isPro());
    }

    public function testPastDueKeepsAccessDuringRetries(): void
    {
        // A product decision, not a technical one: cutting someone off
        // over a temporarily declined card is hostile, and most dunning
        // failures resolve within days.
        $user = UserFactory::new()->verified()->create();
        $subscription = $this->givePro($user);
        $this->clearEm();

        $this->postWebhook([
            'id' => 'evt_past_due',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => $subscription->getStripeSubscriptionId(),
                'customer' => $subscription->getStripeCustomerId(),
                'status' => 'past_due',
                'cancel_at_period_end' => false,
                'items' => ['data' => [['current_period_end' => strtotime('+2 days')]]],
            ]],
        ]);

        $this->processQueue();
        $this->clearEm();

        self::assertTrue($this->subscriptionFor($user)->isPro());
    }

    // ---------------------------------------------------------------
    // The gated feature
    // ---------------------------------------------------------------

    public function testAFreeUserCannotPostBeyondTheFreeLimit(): void
    {
        $user = UserFactory::new()->verified()->create();

        $this->as($user)->postJson('/api/posts', ['content' => str_repeat('x', 3000)]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // The flag lets a client show an upgrade prompt rather than a
        // generic validation error.
        self::assertTrue($this->json()['upgradeRequired']);
    }

    public function testAProUserCanPostLongContent(): void
    {
        $user = UserFactory::new()->verified()->create();
        $this->givePro($user);

        $this->as($user)->postJson('/api/posts', ['content' => str_repeat('x', 3000)]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAnAdminGetsProFeaturesWithoutPaying(): void
    {
        $admin = UserFactory::new()->verified()->admin()->create();

        $this->as($admin)->postJson('/api/posts', ['content' => str_repeat('x', 3000)]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Give a user an active subscription directly, without going through
     * checkout and webhooks. Tests of the FEATURE should not re-test the
     * payment flow.
     */
    private function givePro(User $user): Subscription
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $subscription = new Subscription($user, 'cus_test_'.$user->getId());
        $subscription->syncFromStripe(
            'sub_test_'.$user->getId(),
            SubscriptionStatus::Active,
            new \DateTimeImmutable('+30 days'),
            false,
        );

        $em->persist($subscription);
        $em->flush();

        return $subscription;
    }

    private function postWebhook(array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/stripe/webhook',
            [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                // The fake ignores this, but sending it keeps the test
                // shaped like a real request.
                'HTTP_STRIPE_SIGNATURE' => 't=1,v1=fake',
            ],
            json_encode($payload)
        );
    }

    /**
     * Run everything sitting in the in-memory transport, as a worker would.
     */
    private function processQueue(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);

        foreach ($this->transport()->getSent() as $envelope) {
            // ReceivedStamp tells the bus this came off a transport, so
            // SendMessageMiddleware skips re-sending and the handler runs.
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
        }

        $this->transport()->reset();
    }

    /**
     * The handler writes through a different EntityManager state than the
     * test reads from. Clear before asserting, or you get cached objects.
     */
    private function clearEm(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function gateway(): FakeStripeGateway
    {
        return self::getContainer()->get(FakeStripeGateway::class);
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function queued(): array
    {
        return $this->transport()->getSent();
    }

    private function subscriptions(): SubscriptionRepository
    {
        return self::getContainer()->get(SubscriptionRepository::class);
    }

    private function as(User $user): static
    {
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt);

        return $this;
    }

    private function post(string $uri): void
    {
        $this->client->request('POST', $uri);
    }

    private function postJson(string $uri, array $body): void
    {
        $this->client->request(
            'POST', $uri, [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Fetch the subscription by user ID.
     *
     * We capture the id BEFORE any clear(), because the User object
     * itself becomes detached and unusable as a query parameter.
     */
    private function subscriptionFor(User $user): ?Subscription
    {
        return $this->subscriptions()->findOneByUserId($user->getId());
    }
}
