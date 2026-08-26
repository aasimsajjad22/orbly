<?php

namespace App\Tests\Functional;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests: they exercise the full request/response cycle
 * (routing -> firewall -> controller -> JSON), not individual classes.
 */
class AuthenticationTest extends WebTestCase
{
    // The client is Symfony's fake browser. It calls Kernel::handle()
    // directly — no HTTP, no running server.
    private KernelBrowser $client;

    protected function setUp(): void
    {
        // The second argument sets default $_SERVER vars for EVERY request this
        // client makes. Without HTTP_ACCEPT, Symfony's error handler renders the
        // HTML exception page instead of the RFC 7807 JSON body, and any
        // json_decode() of an error response returns null.
        $this->client = self::createClient([], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    // ---------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------

    public function testItRegistersANewUser(): void
    {
        // request() args: METHOD, URI, params, files, server-vars, body.
        // The empty arrays are placeholders we don't need — the JSON goes
        // in the LAST argument, as a raw string.
        $this->client->request(
            'POST',
            '/api/register',
            [],
            [],
            // "server" vars become $_SERVER. HTTP headers are prefixed
            // with HTTP_, except CONTENT_TYPE which has no prefix.
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'newuser@orbly.test',
                'password' => 'secret1234',
                'displayName' => 'New User',
            ])
        );

        // 201, not 200 — a resource was created.
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Decode the JSON body so we can assert on its contents.
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame('newuser@orbly.test', $data['email']);
        self::assertSame('New User', $data['displayName']);

        // The security assertion: the hash must NEVER leave the app.
        self::assertArrayNotHasKey('password', $data);

        // And confirm it actually hit the database, not just returned JSON.
        UserFactory::assert()->exists(['email' => 'newuser@orbly.test']);
    }

    public function testItLowercasesTheEmailOnRegistration(): void
    {
        $this->postJson('/api/register', [
            'email' => '  MiXeD@Orbly.TEST ',
            'password' => 'secret1234',
            'displayName' => 'Mixed Case',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Proves User::setEmail() normalised the value before persisting.
        UserFactory::assert()->exists(['email' => 'mixed@orbly.test']);
    }

    public function testItRejectsInvalidRegistrationData(): void
    {
        $this->postJson('/api/register', [
            'email' => 'not-an-email',
            'password' => 'short',
            'displayName' => 'S',
        ]);

        // 422 = well-formed request, but the content failed validation.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $data = json_decode($this->client->getResponse()->getContent(), true);

        // All three failures reported at once, not just the first.
        $failedFields = array_column($data['violations'], 'propertyPath');
        self::assertContains('email', $failedFields);
        self::assertContains('password', $failedFields);
        self::assertContains('displayName', $failedFields);

        // Nothing was written — the controller body never ran.
        UserFactory::assert()->count(0);
    }

    public function testItRejectsADuplicateEmail(): void
    {
        // Arrange: a user already owns this email.
        UserFactory::createOne(['email' => 'taken@orbly.test']);

        $this->postJson('/api/register', [
            'email' => 'taken@orbly.test',
            'password' => 'secret1234',
            'displayName' => 'Impostor',
        ]);

        // 409 Conflict — valid request, clashes with existing state.
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // Still exactly one user: no second row was created.
        UserFactory::assert()->count(1);
    }

    // ---------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------

    public function testItReturnsAJwtForValidCredentials(): void
    {
        UserFactory::createOne([
            'email' => 'login@orbly.test',
            'password' => 'secret1234',   // the factory hashes this for us
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@orbly.test',
            'password' => 'secret1234',
        ]);

        self::assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);

        // A JWT is three dot-separated parts: header.payload.signature
        self::assertCount(3, explode('.', $data['token']));
    }

    public function testItRejectsAWrongPassword(): void
    {
        UserFactory::createOne([
            'email' => 'login@orbly.test',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@orbly.test',
            'password' => 'WRONG-PASSWORD',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testItRejectsAnUnknownEmail(): void
    {
        $this->postJson('/api/login', [
            'email' => 'ghost@orbly.test',
            'password' => 'secret1234',
        ]);

        // Note: same 401 and same message as a wrong password. That is
        // deliberate — a different response would let an attacker discover
        // which emails are registered (user enumeration).
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ---------------------------------------------------------------
    // Protected endpoint
    // ---------------------------------------------------------------

    public function testMeRequiresTheToken(): void
    {
        // No Authorization header at all.
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeRejectsAGarbageToken(): void
    {
        $this->client->request('GET', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer this.is.not-a-real-token',
        ]);

        // Lexik's JWTAuthenticator fails the signature check -> 401.
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeReturnsTheAuthenticatedUser(): void
    {
        UserFactory::createOne([
            'email' => 'me@orbly.test',
            'displayName' => 'Me Myself',
            'password' => 'secret1234',
        ]);

        // Step 1: log in to obtain a REAL token, signed by the test keypair.
        $token = $this->loginAndGetToken('me@orbly.test', 'secret1234');

        // Step 2: use it. Header name is HTTP_ + AUTHORIZATION.
        $this->client->request('GET', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);

        self::assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('me@orbly.test', $data['email']);
        self::assertSame('Me Myself', $data['displayName']);
        self::assertContains('ROLE_USER', $data['roles']);

        // Again: the hash must never appear in a response.
        self::assertArrayNotHasKey('password', $data);
    }

    public function testARegisteredUserCanImmediatelyLogIn(): void
    {
        // The end-to-end proof that hashPassword() at registration and
        // verify() at login agree on the hash format.
        $this->postJson('/api/register', [
            'email' => 'roundtrip@orbly.test',
            'password' => 'secret1234',
            'displayName' => 'Round Trip',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $token = $this->loginAndGetToken('roundtrip@orbly.test', 'secret1234');
        self::assertNotEmpty($token);
    }

    // ---------------------------------------------------------------
    // Small helpers to keep the tests above readable
    // ---------------------------------------------------------------

    /**
     * Wraps the verbose request() call for JSON POSTs.
     */
    private function postJson(string $uri, array $body): void
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );
    }

    /**
     * Logs in and returns the raw JWT string.
     */
    private function loginAndGetToken(string $email, string $password): string
    {
        $this->postJson('/api/login', ['email' => $email, 'password' => $password]);

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }
}
