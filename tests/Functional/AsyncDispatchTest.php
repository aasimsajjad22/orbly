<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Message\FriendRequestSent;
use App\Message\SendVerificationEmail;
use App\Factory\UserFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Tests that the right messages get QUEUED. Not what the handlers do —
 * that is covered separately, so a failure here points at the controller
 * and a failure there points at the handler.
 */
#[ResetDatabase]
class AsyncDispatchTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function testRegistrationQueuesTheVerificationEmail(): void
    {
        $this->postJson('/api/register', [
            'email' => 'queued@orbly.test',
            'password' => 'secret1234',
            'displayName' => 'Queued',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Exactly one message was queued.
        $messages = $this->queuedMessages();
        self::assertCount(1, $messages);

        // ...of the right type, carrying the right id.
        $message = $messages[0]->getMessage();
        self::assertInstanceOf(SendVerificationEmail::class, $message);

        $user = self::getContainer()->get(\App\Repository\UserRepository::class)
            ->findOneByEmail('queued@orbly.test');

        self::assertSame($user->getId(), $message->userId);
    }

    public function testRegistrationSendsNoEmailDirectly(): void
    {
        // THE test that proves the work moved off the request. Before this
        // phase, registration sent the email inline and assertEmailCount(1)
        // would have passed. Now the handler sends it, in a worker.
        $this->postJson('/api/register', [
            'email' => 'nodirect@orbly.test',
            'password' => 'secret1234',
            'displayName' => 'No Direct',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Nothing was sent during the request itself.
        self::assertEmailCount(0);

        // But something was queued.
        self::assertCount(1, $this->queuedMessages());
    }

    public function testAFailedRegistrationQueuesNothing(): void
    {
        // Validation fails before the controller body runs, so no message
        // should exist. Catches the mistake of dispatching too early.
        $this->postJson('/api/register', [
            'email' => 'not-an-email',
            'password' => 'short',
            'displayName' => 'X',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(0, $this->queuedMessages());
    }

    public function testSendingAFriendRequestQueuesANotification(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $bob = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $messages = $this->queuedMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(FriendRequestSent::class, $messages[0]->getMessage());
    }

    public function testAnAutoAcceptedRequestQueuesNoNotification(): void
    {
        // If they both requested each other they are already friends, so
        // a "wants to connect" email would be nonsense. This asserts the
        // dispatch is on the right branch of send().
        $alice = UserFactory::new()->verified()->create();
        $bob = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $this->transport()->reset();   // clear the first notification

        $this->as($bob)->postJson('/api/friend-requests', ['recipientId' => $alice->getId()]);

        self::assertTrue($this->json()['autoAccepted']);
        self::assertCount(0, $this->queuedMessages());
    }

    public function testADuplicateRequestQueuesNoSecondNotification(): void
    {
        // Sending twice returns the existing request rather than creating
        // one — so it must not email the recipient again.
        $alice = UserFactory::new()->verified()->create();
        $bob = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $this->transport()->reset();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);

        self::assertCount(0, $this->queuedMessages());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * The in-memory transport, cast so the IDE knows about get()/reset().
     */
    private function transport(): InMemoryTransport
    {
        // The service id is messenger.transport.<name from messenger.yaml>
        return self::getContainer()->get('messenger.transport.async');
    }

    /**
     * Everything queued so far, as Envelopes.
     *
     * ->getMessage() unwraps the Envelope to get your message object back.
     */
    private function queuedMessages(): array
    {
        return $this->transport()->getSent();
    }

    private function as(User $user): static
    {
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt);

        return $this;
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
}
