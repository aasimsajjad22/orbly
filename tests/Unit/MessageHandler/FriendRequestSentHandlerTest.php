<?php

namespace App\Tests\Unit\MessageHandler;

use App\Factory\FriendRequestFactory;
use App\Factory\UserFactory;
use App\Message\FriendRequestSent;
use App\MessageHandler\FriendRequestSentHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Handler tests: call __invoke() directly with a message, no HTTP.
 *
 * KernelTestCase, not WebTestCase — we need the container but not a
 * browser. Faster, and the failure points straight at the handler.
 */
#[ResetDatabase]
class FriendRequestSentHandlerTest extends KernelTestCase
{
    private FriendRequestSentHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(FriendRequestSentHandler::class);
    }

    public function testItEmailsTheRecipient(): void
    {
        $sender = UserFactory::new()->verified()->create(['displayName' => 'Alice']);
        $recipient = UserFactory::new()->verified()->create([
            'email' => 'bob@orbly.test',
            'displayName' => 'Bob',
        ]);

        $request = FriendRequestFactory::createOne([
            'sender' => $sender,
            'recipient' => $recipient,
        ]);

        // Invoke the handler exactly as a worker would.
        ($this->handler)(new FriendRequestSent($request->getId()));

        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertEmailHeaderSame($email, 'To', 'bob@orbly.test');
        self::assertStringContainsString('Alice', $email->getSubject());
    }

    public function testItSendsNothingIfTheRequestWasDeleted(): void
    {
        // The row vanished between dispatch and processing. Must return
        // cleanly, NOT throw — throwing would trigger pointless retries.
        ($this->handler)(new FriendRequestSent(999999));

        self::assertEmailCount(0);
    }

    public function testItSendsNothingIfTheRequestIsNoLongerPending(): void
    {
        // THE race this design exists to handle: the recipient accepted
        // while the message sat in the queue. Emailing "X wants to
        // connect" after they are already friends would be confusing.
        $request = FriendRequestFactory::new()->accepted()->create();

        ($this->handler)(new FriendRequestSent($request->getId()));

        self::assertEmailCount(0);
    }

    public function testItSendsNothingToAnUnverifiedAddress(): void
    {
        // We have no proof the address belongs to them, and mailing
        // unconfirmed addresses damages sending reputation.
        $recipient = UserFactory::createOne();   // unverified by default

        $request = FriendRequestFactory::createOne(['recipient' => $recipient]);

        ($this->handler)(new FriendRequestSent($request->getId()));

        self::assertEmailCount(0);
    }
}
