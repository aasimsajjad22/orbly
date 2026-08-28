<?php

namespace App\Tests\Unit\MessageHandler;

use App\Factory\UserFactory;
use App\Message\SendVerificationEmail;
use App\MessageHandler\SendVerificationEmailHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
class SendVerificationEmailHandlerTest extends KernelTestCase
{
    private SendVerificationEmailHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(SendVerificationEmailHandler::class);
    }

    public function testItSendsTheVerificationEmail(): void
    {
        $user = UserFactory::createOne(['email' => 'verify@orbly.test']);

        ($this->handler)(new SendVerificationEmail($user->getId()));

        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertEmailHeaderSame($email, 'To', 'verify@orbly.test');
        self::assertStringContainsString('/api/verify-email', $email->getHtmlBody());
    }

    public function testItSkipsADeletedUser(): void
    {
        ($this->handler)(new SendVerificationEmail(999999));

        self::assertEmailCount(0);
    }

    public function testItSkipsAnAlreadyVerifiedUser(): void
    {
        // They clicked a link from an earlier email while this message
        // waited. A second one would be confusing.
        $user = UserFactory::new()->verified()->create();

        ($this->handler)(new SendVerificationEmail($user->getId()));

        self::assertEmailCount(0);
    }

    public function testItIsIdempotent(): void
    {
        // A message can be delivered more than once — a worker might crash
        // after sending but before acknowledging. Running the same handler
        // twice must not be harmful.
        $user = UserFactory::createOne();

        ($this->handler)(new SendVerificationEmail($user->getId()));
        ($this->handler)(new SendVerificationEmail($user->getId()));

        // Two emails, because the user is still unverified both times.
        // That is acceptable — a duplicate verification email is annoying,
        // not dangerous. Compare with a payment handler, where a duplicate
        // would charge twice and idempotency is essential. Phase 6 deals
        // with that properly.
        self::assertEmailCount(2);
    }
}
