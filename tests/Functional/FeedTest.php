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
class FeedTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function _testTheFeedShowsFriendsPostsOnly(): void
    {
        $me = UserFactory::new()->verified()->create();
        $friend = UserFactory::new()->verified()->create();
        $stranger = UserFactory::new()->verified()->create();

        $this->friendships()->create($me, $friend);

        PostFactory::createMany(3, ['author' => $friend]);
        PostFactory::createMany(5, ['author' => $stranger]);
        PostFactory::createMany(2, ['author' => $me]);   // own posts excluded too

        $this->as($me)->get('/api/feed?limit=50');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->json()['items']);
    }

    public function testTheFeedExcludesPrivatePosts(): void
    {
        $me = UserFactory::new()->verified()->create();
        $friend = UserFactory::new()->verified()->create();
        $this->friendships()->create($me, $friend);

        PostFactory::createMany(2, ['author' => $friend]);              // public
        PostFactory::new()->friendsOnly()->many(2)->create(['author' => $friend]);
        PostFactory::new()->private()->many(3)->create(['author' => $friend]);

        $this->as($me)->get('/api/feed?limit=50');

        // Public + friends-only = 4. Private never appears in anyone
        // else's feed, friendship or not.
        self::assertCount(4, $this->json()['items']);
    }

    public function testTheFeedExcludesDeletedPosts(): void
    {
        $me = UserFactory::new()->verified()->create();
        $friend = UserFactory::new()->verified()->create();
        $this->friendships()->create($me, $friend);

        PostFactory::createMany(2, ['author' => $friend]);
        PostFactory::new()->deleted()->many(3)->create(['author' => $friend]);

        $this->as($me)->get('/api/feed?limit=50');

        self::assertCount(2, $this->json()['items']);
    }

    public function testBlockingRemovesPostsFromTheFeed(): void
    {
        $me = UserFactory::new()->verified()->create();
        $friend = UserFactory::new()->verified()->create();
        $this->friendships()->create($me, $friend);

        PostFactory::createMany(3, ['author' => $friend]);

        $this->as($me)->get('/api/feed?limit=50');
        self::assertCount(3, $this->json()['items']);

        // Blocking also destroys the friendship, so the posts vanish for
        // two reasons at once. Both are correct.
        $this->as($me)->post('/api/blocks/'.$friend->getId());

        $this->as($me)->get('/api/feed?limit=50');
        self::assertCount(0, $this->json()['items']);
    }

    // ---------------------------------------------------------------
    // Cursor pagination
    // ---------------------------------------------------------------

    public function testItPagesThroughTheFeedWithoutRepeatingPosts(): void
    {
        $me = UserFactory::new()->verified()->create();
        $friend = UserFactory::new()->verified()->create();
        $this->friendships()->create($me, $friend);

        PostFactory::createMany(12, ['author' => $friend]);

        $seen = [];
        $cursor = null;
        $pages = 0;

        // Walk every page, collecting ids.
        do {
            $url = '/api/feed?limit=5'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
            $this->as($me)->get($url);

            self::assertResponseIsSuccessful();

            $data = $this->json();

            foreach ($data['items'] as $item) {
                $seen[] = $item['id'];
            }

            $cursor = $data['nextCursor'];
            $pages++;

            // Guard against an infinite loop if the cursor logic is broken.
            self::assertLessThan(10, $pages, 'Paging did not terminate.');
        } while ($cursor !== null);

        // Every post seen exactly once: no duplicates, none skipped.
        self::assertCount(12, $seen);
        self::assertCount(12, array_unique($seen), 'The same post appeared on more than one page.');
    }

    public function testANewPostDoesNotShiftAnAlreadyFetchedPage(): void
    {
        // THE reason cursor pagination exists. With OFFSET, inserting a
        // post between the two requests would push everything down by one
        // and page 2 would repeat the last item of page 1.
        $me = UserFactory::new()->verified()->create();
        $friend = UserFactory::new()->verified()->create();
        $this->friendships()->create($me, $friend);

        PostFactory::createMany(10, ['author' => $friend]);

        $this->as($me)->get('/api/feed?limit=5');
        $page1 = array_column($this->json()['items'], 'id');
        $cursor = $this->json()['nextCursor'];

        // Someone posts while we are reading.
        PostFactory::createOne(['author' => $friend, 'content' => 'Brand new']);

        $this->as($me)->get('/api/feed?limit=5&cursor='.urlencode($cursor));
        $page2 = array_column($this->json()['items'], 'id');

        // No overlap. The new post is simply not in page 2, because it is
        // not older than the cursor.
        self::assertEmpty(array_intersect($page1, $page2), 'Pages overlapped after a new post was created.');
    }

    public function testAMalformedCursorIs400(): void
    {
        $me = UserFactory::new()->verified()->create();

        $this->as($me)->get('/api/feed?cursor=this-is-not-a-cursor');

        // Not a silent fallback to page 1 — that would make a broken
        // client loop forever thinking it was paging.
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ---------------------------------------------------------------
    // Likes and comments
    // ---------------------------------------------------------------

    public function testLikingIncrementsTheCounter(): void
    {
        $me = UserFactory::new()->verified()->create();
        $post = PostFactory::createOne();

        $this->as($me)->post('/api/posts/'.$post->getId().'/like');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['created']);
        self::assertSame(1, $this->json()['likeCount']);

        // The stored counter must match the real rows.
        $this->assertCounterMatchesReality($post->getId());
    }

    public function testLikingTwiceIsIdempotent(): void
    {
        $me = UserFactory::new()->verified()->create();
        $post = PostFactory::createOne();

        $this->as($me)->post('/api/posts/'.$post->getId().'/like');
        $this->as($me)->post('/api/posts/'.$post->getId().'/like');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['created']);
        // Still 1 — not 2.
        self::assertSame(1, $this->json()['likeCount']);

        $this->assertCounterMatchesReality($post->getId());
    }

    public function testUnlikingDecrements(): void
    {
        $me = UserFactory::new()->verified()->create();
        $post = PostFactory::createOne();

        $this->as($me)->post('/api/posts/'.$post->getId().'/like');
        $this->as($me)->delete('/api/posts/'.$post->getId().'/like');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['likeCount']);

        $this->assertCounterMatchesReality($post->getId());
    }

    public function testYouCannotLikeAPostYouCannotSee(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $stranger = UserFactory::new()->verified()->create();

        $post = PostFactory::new()->private()->create(['author' => $alice]);

        $this->as($stranger)->post('/api/posts/'.$post->getId().'/like');

        // 404, matching the show endpoint — liking must not become a way
        // to discover which posts exist.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCommentingIncrementsTheCounter(): void
    {
        $me = UserFactory::new()->verified()->create();
        $post = PostFactory::createOne();

        $this->as($me)->postJson('/api/posts/'.$post->getId().'/comments', [
            'content' => 'Nice one',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Nice one', $this->json()['content']);

        $this->assertCounterMatchesReality($post->getId());
    }

    public function testThePostAuthorCanDeleteAnyComment(): void
    {
        // The interesting authorization case: TWO different people may
        // delete a comment, for different reasons. This is the moderation
        // half — the post's author removing something from their thread.
        $alice = UserFactory::new()->verified()->create();
        $bob = UserFactory::new()->verified()->create();

        $post = PostFactory::createOne(['author' => $alice]);

        $this->as($bob)->postJson('/api/posts/'.$post->getId().'/comments', ['content' => 'Rude']);
        $commentId = $this->json()['id'];

        $this->as($alice)->delete('/api/comments/'.$commentId);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->assertCounterMatchesReality($post->getId());
    }

    public function testTheCommentAuthorCanDeleteTheirOwnComment(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $bob = UserFactory::new()->verified()->create();

        $post = PostFactory::createOne(['author' => $alice]);

        $this->as($bob)->postJson('/api/posts/'.$post->getId().'/comments', ['content' => 'Oops']);
        $commentId = $this->json()['id'];

        $this->as($bob)->delete('/api/comments/'.$commentId);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testAThirdPartyCannotDeleteAComment(): void
    {
        $alice = UserFactory::new()->verified()->create();
        $bob = UserFactory::new()->verified()->create();
        $carol = UserFactory::new()->verified()->create();

        $post = PostFactory::createOne(['author' => $alice]);

        $this->as($bob)->postJson('/api/posts/'.$post->getId().'/comments', ['content' => 'Mine']);
        $commentId = $this->json()['id'];

        $this->as($carol)->delete('/api/comments/'.$commentId);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * The denormalized counters must always agree with the real rows.
     *
     * This is the assertion that justifies storing them at all — if it
     * ever fails, a transaction is not doing its job.
     */
    private function assertCounterMatchesReality(int $postId): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $row = $conn->fetchAssociative(
            'SELECT p.like_count, p.comment_count,
                    (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS real_likes,
                    (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id)   AS real_comments
             FROM posts p WHERE p.id = :id',
            ['id' => $postId]
        );

        self::assertSame((int) $row['real_likes'], (int) $row['like_count'], 'like_count drifted from the real rows.');
        self::assertSame((int) $row['real_comments'], (int) $row['comment_count'], 'comment_count drifted.');
    }

    private function friendships(): FriendshipService
    {
        return self::getContainer()->get(FriendshipService::class);
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

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri);
    }

    private function post(string $uri): void
    {
        $this->client->request('POST', $uri);
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
}
