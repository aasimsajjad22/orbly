<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Factory\PostFactory;
use App\Factory\UserFactory;
use App\Service\FriendshipService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
class PostTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_ACCEPT' => 'application/json']);
    }

    // ---------------------------------------------------------------
    // Creating
    // ---------------------------------------------------------------

    public function testItCreatesAPost(): void
    {
        $alice = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/posts', [
            'content' => 'Hello Orbly',
            'visibility' => 'public',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = $this->json();
        self::assertSame('Hello Orbly', $data['content']);
        self::assertSame('public', $data['visibility']);
        self::assertSame(0, $data['likeCount']);

        // The nested author is serialized with user:public — so the
        // display name is there and the email is NOT.
        self::assertSame($alice->getDisplayName(), $data['author']['displayName']);
        self::assertArrayNotHasKey('email', $data['author']);
    }

    public function testItDefaultsToPublicVisibility(): void
    {
        $alice = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/posts', ['content' => 'No visibility given']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('public', $this->json()['visibility']);
    }

    public function testItRejectsAnEmptyPost(): void
    {
        $alice = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/posts', ['content' => '   ']);

        // The DTO trims, so whitespace-only fails NotBlank.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testItRejectsAnUnknownVisibility(): void
    {
        $alice = UserFactory::new()->verified()->create();

        $this->as($alice)->postJson('/api/posts', [
            'content' => 'Test',
            'visibility' => 'nonsense',
        ]);

        // The enum type-hint means the serializer refuses to build the DTO,
        // giving a clean 4xx rather than a 500 deep in the controller.
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Visibility — the 404-not-403 rule
    // ---------------------------------------------------------------

    public function testAnyoneCanSeeAPublicPost(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $stranger = UserFactory::new()->verified()->create();

        $post = PostFactory::createOne(['author' => $alice]);

        $this->as($stranger)->get('/api/posts/'.$post->getId());

        self::assertResponseIsSuccessful();
    }

    public function testAPrivatePostIs404ForOthers(): void
    {
        // THE information-leak test. A 403 here would confirm the post
        // exists — someone could walk the ids and map who posts and when.
        $alice = UserFactory::new()->verified()->create();
        $stranger = UserFactory::new()->verified()->create();

        $post = PostFactory::new()->private()->create(['author' => $alice]);

        $this->as($stranger)->get('/api/posts/'.$post->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheAuthorCanSeeTheirOwnPrivatePost(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $post = PostFactory::new()->private()->create(['author' => $alice]);

        $this->as($alice)->get('/api/posts/'.$post->getId());

        self::assertResponseIsSuccessful();
    }

    public function testAFriendsOnlyPostIsHiddenFromNonFriends(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $stranger = UserFactory::new()->verified()->create();

        $post = PostFactory::new()->friendsOnly()->create(['author' => $alice]);

        $this->as($stranger)->get('/api/posts/'.$post->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAFriendsOnlyPostIsVisibleToFriends(): void
    {
        [$alice, $bob] = $this->twoFriends();

        $post = PostFactory::new()->friendsOnly()->create(['author' => $alice]);

        $this->as($bob)->get('/api/posts/'.$post->getId());

        self::assertResponseIsSuccessful();
    }

    public function testABlockedUserCannotSeeEvenPublicPosts(): void
    {
        // Block beats visibility. The check order in PostVoter::canView()
        // is author -> block -> visibility, and this proves it.
        [$alice, $bob] = $this->twoFriends();

        $post = PostFactory::createOne(['author' => $alice]);   // public

        $this->as($alice)->post('/api/blocks/'.$bob->getId());

        $this->as($bob)->get('/api/posts/'.$post->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ---------------------------------------------------------------
    // Editing and deleting
    // ---------------------------------------------------------------

    public function testTheAuthorCanEditTheirPost(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $post = PostFactory::createOne(['author' => $alice, 'content' => 'Original']);

        $this->as($alice)->patchJson('/api/posts/'.$post->getId(), ['content' => 'Edited']);

        self::assertResponseIsSuccessful();
        self::assertSame('Edited', $this->json()['content']);

        // PreUpdate set updatedAt, so the virtual "edited" field flips.
        self::assertTrue($this->json()['edited']);
    }

    public function testOthersCannotEditAPost(): void
    {
        // 403 here, not 404 — the post is publicly visible, so refusing
        // the edit reveals nothing the caller did not already know.
        $alice = UserFactory::new()->verified()->create();
        $bob = UserFactory::new()->verified()->create();

        $post = PostFactory::createOne(['author' => $alice]);

        $this->as($bob)->patchJson('/api/posts/'.$post->getId(), ['content' => 'Hacked']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPatchOnlyChangesWhatWasSent(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $post = PostFactory::new()->friendsOnly()->create([
            'author' => $alice,
            'content' => 'Original',
        ]);

        // Send content only — visibility must be left alone.
        $this->as($alice)->patchJson('/api/posts/'.$post->getId(), ['content' => 'Changed']);

        self::assertResponseIsSuccessful();
        self::assertSame('Changed', $this->json()['content']);
        self::assertSame('friends', $this->json()['visibility']);
    }

    public function testDeletingIsSoft(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $post = PostFactory::createOne(['author' => $alice]);
        $id = $post->getId();

        $this->as($alice)->delete('/api/posts/'.$id);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // The row is still there, with deleted_at set — raw SQL, because
        // the ORM read would go through the identity map.
        $row = $this->fetchOne('SELECT deleted_at FROM posts WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row, 'The row should still exist after a soft delete.');
    }

    public function testADeletedPostIsInvisibleEvenToItsAuthor(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $post = PostFactory::new()->deleted()->create(['author' => $alice]);

        $this->as($alice)->get('/api/posts/'.$post->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return array{0: User, 1: User} */
    private function twoFriends(): array
    {
        $a = UserFactory::new()->verified()->create();
        $b = UserFactory::new()->verified()->create();

        self::getContainer()->get(FriendshipService::class)->create($a, $b);

        return [$a, $b];
    }

    /**
     * Authenticate by minting a real JWT and setting the header.
     *
     * loginUser() does not work here: it stores a token in the session,
     * but the api firewall is stateless and Lexik only reads the
     * Authorization header.
     */
    private function as(User $user): static
    {
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt);

        return $this;
    }

    private function postJson(string $uri, array $body): void
    {
        $this->sendJson('POST', $uri, $body);
    }

    private function patchJson(string $uri, array $body): void
    {
        $this->sendJson('PATCH', $uri, $body);
    }

    private function sendJson(string $method, string $uri, array $body): void
    {
        $this->client->request(
            $method, $uri, [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );
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
     * Raw SQL read. Bypasses the ORM entirely — no identity map, no stale
     * cached objects, no detached-entity problems.
     */
    private function fetchOne(string $sql, array $params = []): mixed
    {
        return self::getContainer()
            ->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne($sql, $params);
    }

    /**
     * POST with no body — for endpoints like /api/blocks/{id} that take
     * everything they need from the URL.
     */
    private function post(string $uri): void
    {
        $this->client->request('POST', $uri);
    }
}
