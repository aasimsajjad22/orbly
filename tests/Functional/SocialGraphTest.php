<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\FriendRequestStatus;
use App\Factory\UserFactory;
use App\Repository\FriendRequestRepository;
use App\Repository\FriendshipRepository;
use App\Service\FriendshipService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
class SocialGraphTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_ACCEPT' => 'application/json']);
    }

    // ---------------------------------------------------------------
    // Sending
    // ---------------------------------------------------------------

    public function testItSendsAFriendRequest(): void
    {
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('pending', $this->json()['status']);
        self::assertFalse($this->json()['autoAccepted']);
    }

    public function testYouCannotFriendYourself(): void
    {
        [$alice] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $alice->getId()]);

        // 422: well-formed request, broken business rule.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testSendingTwiceReturnsTheSameRequest(): void
    {
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $firstId = $this->json()['id'];

        // A double click is normal behaviour, not an attack.
        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);

        self::assertSame($firstId, $this->json()['id']);

        // And crucially: no second row.
        self::assertCount(1, $this->requests()->findAll());
    }

    public function testSendingBackAutoAcceptsTheOriginal(): void
    {
        // The mutual-consent rule. Without it you get two opposing pending
        // requests and no clear way to resolve them.
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $this->as($bob)->postJson('/api/friend-requests', ['recipientId' => $alice->getId()]);

        self::assertTrue($this->json()['autoAccepted']);
        self::assertSame('accepted', $this->json()['status']);

        // ONE row, not two.
        self::assertCount(1, $this->requests()->findAll());

        // And a real friendship exists.
        $this->assertFriends($alice, $bob);
    }

    // ---------------------------------------------------------------
    // The Voter
    // ---------------------------------------------------------------

    public function testOnlyTheRecipientCanAccept(): void
    {
        // THE voter test. If this passes with a 200, anyone can accept
        // anyone's requests and the authorization layer is decorative.
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $id = $this->json()['id'];

        // The SENDER tries to accept their own request.
        $this->as($alice)->post("/api/friend-requests/{$id}/accept");

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->friendships()->areFriends($alice, $bob));
    }

    public function testTheRecipientCanAccept(): void
    {
        [$alice, $bob] = $this->twoUsers();

        // Alice sends the request.
        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $id = $this->json()['id'];

        // Bob is the recipient, so the Voter lets him through.
        $this->as($bob)->post("/api/friend-requests/{$id}/accept");

        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->json()['status']);

        // friendships() clears the EntityManager before returning the
        // repository, so this reads the database rather than the identity
        // map — the write happened inside the kernel's request cycle.
        $this->assertFriends($alice, $bob);
    }

    public function testOnlyTheSenderCanCancel(): void
    {
        // The mirror image: recipients decline, senders cancel. Different
        // people, different actions, different voter attributes.
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $id = $this->json()['id'];

        // Recipient tries to cancel — not their action to take.
        $this->as($bob)->delete("/api/friend-requests/{$id}");
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Sender can.
        $this->as($alice)->delete("/api/friend-requests/{$id}");
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->json()['status']);
    }

    public function testAnUninvolvedUserCanDoNothing(): void
    {
        [$alice, $bob] = $this->twoUsers();
        $carol = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $id = $this->json()['id'];

        $this->as($carol)->post("/api/friend-requests/{$id}/accept");
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->as($carol)->delete("/api/friend-requests/{$id}");
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAcceptingTwiceIs409NotForbidden(): void
    {
        // Permission and state are DIFFERENT problems. Bob is allowed to
        // accept (403 would be wrong) but the request is already answered.
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $id = $this->json()['id'];

        $this->as($bob)->post("/api/friend-requests/{$id}/accept");
        self::assertResponseIsSuccessful();

        $this->as($bob)->post("/api/friend-requests/{$id}/accept");
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testDeclineLeavesNoFriendship(): void
    {
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $id = $this->json()['id'];

        $this->as($bob)->post("/api/friend-requests/{$id}/decline");

        self::assertResponseIsSuccessful();
        self::assertSame('declined', $this->json()['status']);
        self::assertFalse($this->friendships()->areFriends($alice, $bob));
    }

    // ---------------------------------------------------------------
    // The two-row invariant
    // ---------------------------------------------------------------

    public function testAcceptingWritesBothMirrorRows(): void
    {
        // This is the test that guards the whole denormalized design. If
        // only one row is written, areFriends() gives different answers
        // depending on which way round you ask — and nothing crashes.
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);
        $id = $this->json()['id'];
        $this->as($bob)->post("/api/friend-requests/{$id}/accept");

        // Both directions must be present.
        $this->assertFriends($alice, $bob);
        $this->assertFriends($alice, $bob);

        // Exactly two rows for one friendship.
        self::assertCount(2, $this->friendships()->findAll());
    }

    public function testUnfriendingRemovesBothRows(): void
    {
        [$alice, $bob] = $this->makeFriends();

        $this->as($alice)->delete('/api/friends/'.$bob->getId());

        self::assertResponseIsSuccessful();
        self::assertFalse($this->friendships()->areFriends($alice, $bob));
        self::assertFalse($this->friendships()->areFriends($bob, $alice));
        self::assertCount(0, $this->friendships()->findAll());
    }

    public function testFriendsListShowsTheOtherPerson(): void
    {
        [$alice, $bob] = $this->makeFriends();

        $this->as($alice)->get('/api/friends');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['total']);
        // Alice sees Bob, not herself.
        self::assertSame($bob->getId(), $this->json()['items'][0]['id']);
    }

    // ---------------------------------------------------------------
    // Blocking
    // ---------------------------------------------------------------

    public function testBlockingDestroysTheFriendship(): void
    {
        [$alice, $bob] = $this->makeFriends();

        $this->as($alice)->post('/api/blocks/'.$bob->getId());

        self::assertResponseIsSuccessful();

        // Both rows gone — blocking means "remove them from my life",
        // not "hide them going forward".
        self::assertCount(0, $this->friendships()->findAll());
    }

    public function testBlockingCancelsPendingRequests(): void
    {
        [$alice, $bob] = $this->twoUsers();

        $this->as($bob)->postJson('/api/friend-requests', ['recipientId' => $alice->getId()]);
        $id = $this->json()['id'];

        $this->as($alice)->post('/api/blocks/'.$bob->getId());

        // The block used a bulk DQL UPDATE, which changes the database
        // directly and does NOT update objects already in Doctrine's
        // identity map. find() would hand back the stale cached object,
        // so clear the EntityManager to force a fresh read.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $request = $this->requests()->find($id);
        self::assertSame(FriendRequestStatus::Cancelled, $request->getStatus());
    }

    public function testABlockedUserCannotSendRequests(): void
    {
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->post('/api/blocks/'.$bob->getId());

        // Bob, who was blocked, tries to reach Alice.
        $this->as($bob)->postJson('/api/friend-requests', ['recipientId' => $alice->getId()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // The message must NOT reveal that Alice blocked him.
        self::assertStringNotContainsString('block', strtolower($this->json()['message']));
    }

    public function testTheBlockerAlsoCannotSendRequests(): void
    {
        // Deliberately symmetric: if I blocked someone, I should have to
        // unblock before friending them, not slip around my own block.
        [$alice, $bob] = $this->twoUsers();

        $this->as($alice)->post('/api/blocks/'.$bob->getId());
        $this->as($alice)->postJson('/api/friend-requests', ['recipientId' => $bob->getId()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testUnblockingDoesNotRestoreTheFriendship(): void
    {
        [$alice, $bob] = $this->makeFriends();

        $this->as($alice)->post('/api/blocks/'.$bob->getId());
        $this->as($alice)->delete('/api/blocks/'.$bob->getId());

        self::assertResponseIsSuccessful();

        // Gone for good. They can send a fresh request if they want.
        self::assertFalse($this->friendships()->areFriends($alice, $bob));
    }

    public function testYouCannotBlockYourself(): void
    {
        [$alice] = $this->twoUsers();

        $this->as($alice)->post('/api/blocks/'.$alice->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return array{0: User, 1: User} */
    private function twoUsers(): array
    {
        return [
            UserFactory::new()->verified()->create(),
            UserFactory::new()->verified()->create(),
        ];
    }

    /** @return array{0: User, 1: User} */
    private function makeFriends(): array
    {
        [$alice, $bob] = $this->twoUsers();

        // Build the friendship through the service rather than the API, so
        // these tests aren't testing the request flow all over again.
        self::getContainer()->get(FriendshipService::class)->create($alice, $bob);

        return [$alice, $bob];
    }

    private function postJson(string $uri, array $body): void
    {
        $this->client->request(
            'POST', $uri, [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );
    }

    private function post(string $uri): void
    {
        $this->client->request('POST', $uri);
    }

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri);
    }

    private function delete(string $uri): void
    {
        $this->client->request('DELETE', $uri);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Authenticate as a given user for all following requests.
     *
     * loginUser() does NOT work here: it stores a token in the session, but
     * our api firewall is stateless and Lexik only reads the Authorization
     * header. So we mint a real JWT with the same service the login
     * endpoint uses, and set the header directly.
     *
     * setServerParameter() persists across requests on this client, so one
     * call covers every request until we switch users.
     */
    private function as(User $user): static
    {
        $jwt = self::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt);

        return $this;
    }

    private function friendships(): FriendshipRepository
    {
        // Clear the identity map before reading. The write happened inside
        // the kernel's request cycle; this EntityManager still holds objects
        // from before that, and findOneBy() would answer from the cached
        // state rather than from the database.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(FriendshipRepository::class);
    }

    private function requests(): FriendRequestRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(FriendRequestRepository::class);
    }

    private function assertFriends(User $a, User $b): void
    {
        self::assertSame(2, $this->friendshipRowCount($a, $b), 'Expected both mirror rows to exist.');
    }

    private function assertNotFriends(User $a, User $b): void
    {
        self::assertSame(0, $this->friendshipRowCount($a, $b), 'Expected no friendship rows.');
    }

    private function friendshipRowCount(User $a, User $b): int
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM friendships
             WHERE (user_id = :a AND friend_id = :b)
                OR (user_id = :b AND friend_id = :a)',
            ['a' => $a->getId(), 'b' => $b->getId()]
        );
    }
}
