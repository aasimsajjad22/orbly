<?php

namespace App\Tests\Functional;

use App\Factory\UserFactory;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[ResetDatabase]
class EmailVerificationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_ACCEPT' => 'application/json']);
    }

    // ---------------------------------------------------------------
    // Registration sends the email
    // ---------------------------------------------------------------

    public function testRegistrationSendsAVerificationEmail(): void
    {
        $this->postJson('/api/register', [
            'email' => 'newbie@orbly.test',
            'password' => 'secret1234',
            'displayName' => 'Newbie',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // The email is queued, not sent — run the worker's job.
        $this->processQueue();

        // Exactly one email was handed to the mailer during this request.
        self::assertEmailCount(1);

        // getMailerMessage() returns the last message as a Mime\Email object,
        // so we can inspect it like any other PHP object.
        $email = self::getMailerMessage();

        self::assertEmailHeaderSame($email, 'To', 'newbie@orbly.test');
        self::assertEmailHeaderSame($email, 'Subject', 'Confirm your Orbly account');

        // The body must actually contain a usable link, not just render.
        $body = $email->getHtmlBody();
        self::assertStringContainsString('/api/verify-email', $body);
        self::assertStringContainsString('signature=', $body);
        self::assertStringContainsString('id=', $body);   // the param we added
    }

    public function testRegistrationDoesNotReturnAToken(): void
    {
        // Under the hard gate, registering must NOT log you in. This is the
        // test that would have caught my earlier bad suggestion.
        $this->postJson('/api/register', [
            'email' => 'notoken@orbly.test',
            'password' => 'secret1234',
            'displayName' => 'No Token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertArrayNotHasKey('token', $this->json());
    }

    // ---------------------------------------------------------------
    // Clicking the link
    // ---------------------------------------------------------------

    public function testClickingTheLinkVerifiesTheUserAndUnblocksLogin(): void
    {
        // The whole feature, end to end, in one test.
// 1. Register
        $this->postJson('/api/register', [
            'email' => 'flow@orbly.test',
            'password' => 'secret1234',
            'displayName' => 'Flow',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->processQueue();

        // Grab the link NOW. getMailerMessage() reads the profiler of the
        // LAST request, so any request in between wipes it out.
        $verificationUrl = $this->extractVerificationUrl();

        // 2. Login is blocked
        $this->postJson('/api/login', [
            'email' => 'flow@orbly.test',
            'password' => 'secret1234',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // 3. Click the link we captured earlier
        $this->client->request('GET', $verificationUrl);
        self::assertResponseIsSuccessful();

        // 4. Same login now succeeds
        $this->postJson('/api/login', [
            'email' => 'flow@orbly.test',
            'password' => 'secret1234',
        ]);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $this->json());
    }

    public function testClickingTwiceIsHarmless(): void
    {
        $this->registerUnverified('twice@orbly.test');

        $this->processQueue();

        $url = $this->extractVerificationUrl();

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // Users click links twice. It should not look like an error.
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('already verified', $this->json()['message']);
    }

    public function testATamperedSignatureIsRejected(): void
    {
        // THE test that proves the signature is actually checked. Without
        // this, the whole scheme could be decorative and you'd never know.
        $this->registerUnverified('tamper@orbly.test');
        $this->processQueue();

        $url = $this->extractVerificationUrl();

        // Flip one character of the signature.
        $tampered = preg_replace_callback(
            '/signature=([A-Za-z0-9]+)/',
            fn ($m) => 'signature='.($m[1][0] === 'A' ? 'B' : 'A').substr($m[1], 1),
            $url
        );

        $this->client->request('GET', $tampered);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // And crucially: the user is still unverified.
        $user = $this->users()->findOneByEmail('tamper@orbly.test');
        self::assertFalse($user->isEmailVerified());
    }

    public function testAMissingIdIsRejected(): void
    {
        $this->client->request('GET', '/api/verify-email?signature=abc&expires=999&token=xyz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ---------------------------------------------------------------
    // Resend
    // ---------------------------------------------------------------

    public function testResendSendsANewLinkForAnUnverifiedUser(): void
    {
        UserFactory::createOne(['email' => 'resend@orbly.test']);   // unverified by default

        $this->postJson('/api/resend-verification', ['email' => 'resend@orbly.test']);

        self::assertResponseIsSuccessful();
        $this->processQueue();
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(), 'To', 'resend@orbly.test');
    }

    public function testResendRevealsNothingAboutUnknownAddresses(): void
    {
        // The anti-enumeration test. Both requests must be indistinguishable.
        UserFactory::createOne(['email' => 'exists@orbly.test']);

        $this->postJson('/api/resend-verification', ['email' => 'exists@orbly.test']);
        $known = [$this->client->getResponse()->getStatusCode(), $this->json()];

        $this->postJson('/api/resend-verification', ['email' => 'nobody@orbly.test']);
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->json()];

        // Identical status AND identical body — an attacker learns nothing.
        self::assertSame($known, $unknown);
    }

    public function testResendSendsNothingForAnAlreadyVerifiedUser(): void
    {
        UserFactory::new()->verified()->create(['email' => 'done@orbly.test']);

        $this->postJson('/api/resend-verification', ['email' => 'done@orbly.test']);

        // Same friendly 200...
        self::assertResponseIsSuccessful();

        // ...but no email was actually sent. The response lies by design.
        self::assertEmailCount(0);
    }

    // ---------------------------------------------------------------
    // Google users bypass the gate
    // ---------------------------------------------------------------

    public function testGoogleUsersAreNeverBlockedByTheGate(): void
    {
        // ->google() sets emailVerified true, so the UserChecker passes.
        // This is the 2b/2c seam working as designed.
        $user = UserFactory::new()->google()->create(['email' => 'gmail@orbly.test']);

        self::assertTrue($user->isEmailVerified());
        self::assertFalse($user->hasPassword());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Pulls the signed URL out of the last email's HTML body.
     *
     * This is how you test a link-in-email flow: read the real link the
     * app generated rather than constructing one yourself. A hand-built
     * URL would test your test, not the application.
     */
    private function extractVerificationUrl(): string
    {
        $body = self::getMailerMessage()->getHtmlBody();

        preg_match('#href="([^"]*/api/verify-email[^"]*)"#', $body, $matches);

        self::assertNotEmpty($matches, 'No verification link found in the email body.');

        // Twig escapes & as &amp; in HTML attributes — undo that so the
        // query string parses correctly.
        return html_entity_decode($matches[1]);
    }

    private function registerUnverified(string $email): void
    {
        $this->postJson('/api/register', [
            'email' => $email,
            'password' => 'secret1234',
            'displayName' => 'Test User',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
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

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    /**
     * Run every message currently sitting in the in-memory transport.
     *
     * Registration now QUEUES the verification email instead of sending
     * it, so a test that wants the email must play the worker's part
     * first. This is what messenger:consume does, minus the polling loop.
     */
    private function processQueue(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $bus = self::getContainer()->get(MessageBusInterface::class);

        foreach ($transport->getSent() as $envelope) {
            // ReceivedStamp tells the bus "this came off a transport",
            // so SendMessageMiddleware skips re-sending it and the
            // handler actually runs. Without it you would just queue
            // the same message again.
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
        }

        $transport->reset();
    }
}
