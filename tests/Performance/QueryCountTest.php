<?php

namespace App\Tests\Performance;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\FriendshipRepository;
use App\Service\FriendshipService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Query-count tests.
 *
 * These catch a class of bug that ordinary tests miss entirely: code that
 * returns the RIGHT answer using far too many queries. Every assertion here
 * would still pass functionally if the count doubled — which is exactly why
 * N+1 problems reach production.
 */
#[ResetDatabase]
class QueryCountTest extends WebTestCase
{
    public function testFriendsListDoesNotFireOneQueryPerFriend(): void
    {
        $client = self::createClient([], ['HTTP_ACCEPT' => 'application/json']);

        $me = UserFactory::new()->verified()->create();
        $service = self::getContainer()->get(FriendshipService::class);

        // 20 friends. If the query lazy-loads, that's 20 extra SELECTs.
        foreach (range(1, 20) as $i) {
            $friend = UserFactory::new()->verified()->create();
            $service->create($me, $friend);
        }

        $client->loginUser($me, 'api');

        // The profiler collects query counts, but only when explicitly
        // enabled for the request.
        $client->enableProfiler();
        $client->request('GET', '/api/friends');

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        $queries = $profile->getCollector('db')->getQueryCount();

        // Expect roughly two: one for the list (with the joined users
        // eager-loaded), one for the COUNT. The threshold is deliberately
        // loose — we care about "not 20+", not an exact number, so the
        // test doesn't break every time an unrelated query is added.
        //
        // Remove ->addSelect('friend') from findFriendsOf() and re-run:
        // this jumps past 20 and the test fails. That is the N+1 problem,
        // and this is what catches it.
        self::assertLessThan(
            10,
            $queries,
            sprintf('Friends list fired %d queries for 20 friends — likely N+1.', $queries)
        );
    }
}
