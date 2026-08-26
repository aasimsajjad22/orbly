<?php

namespace App\Tests\Functional;

use App\Factory\UserFactory;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
class GoogleSignInTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    // ---------------------------------------------------------------
    // Case 3: brand new user
    // ---------------------------------------------------------------

    public function testItCreatesANewUserOnFirstGoogleSignIn(): void
    {
        $this->signInWithGoogle([
            'sub' => 'google-12345',
            'email' => 'newgoogle@orbly.test',
            'name' => 'New Google User',
        ]);

        // 201 because an account was created.
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = $this->json();

        // A usable Orbly JWT came back, not Google's token.
        self::assertArrayHasKey('token', $data);
        self::assertCount(3, explode('.', $data['token']));

        // isNew lets a frontend show onboarding.
        self::assertTrue($data['isNew']);
        self::assertSame('New Google User', $data['user']['displayName']);

        // Now verify what actually landed in the database.
        $user = $this->userRepository()->findOneByEmail('newgoogle@orbly.test');

        self::assertNotNull($user);
        self::assertSame('google-12345', $user->getGoogleId());

        // Google proved the address, so no confirmation email is needed.
        self::assertTrue($user->isEmailVerified());

        // The whole point of the nullable column: no password exists.
        self::assertNull($user->getPassword());
        self::assertFalse($user->hasPassword());
    }

    public function testItFallsBackToTheEmailPrefixWhenGoogleSendsNoName(): void
    {
        // Google's "name" claim is optional — some accounts have none.
        $this->signInWithGoogle([
            'sub' => 'google-noname',
            'email' => 'noname@orbly.test',
            // no 'name' key at all
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('noname', $this->json()['user']['displayName']);
    }

    // ---------------------------------------------------------------
    // Case 1: returning user
    // ---------------------------------------------------------------

    public function testItLogsInAReturningGoogleUserWithoutCreatingADuplicate(): void
    {
        // Arrange: this Google account already signed in once before.
        UserFactory::new()->google('google-returning')->create([
            'email' => 'returning@orbly.test',
        ]);

        $this->signInWithGoogle([
            'sub' => 'google-returning',
            'email' => 'returning@orbly.test',
        ]);

        // 200, not 201 — nothing was created.
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertFalse($this->json()['isNew']);

        // Still exactly one user: no duplicate row.
        UserFactory::assert()->count(1);
    }

    public function testItMatchesOnGoogleIdEvenWhenTheEmailHasChanged(): void
    {
        // A user changed their Google account's email address. The "sub"
        // claim never changes, so we must still recognise them — this is
        // exactly why we link on sub and not on email.
        UserFactory::new()->google('google-stable-sub')->create([
            'email' => 'old-address@orbly.test',
        ]);

        $this->signInWithGoogle([
            'sub' => 'google-stable-sub',
            'email' => 'brand-new-address@orbly.test',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertFalse($this->json()['isNew']);
        UserFactory::assert()->count(1);
    }

    // ---------------------------------------------------------------
    // Case 2: auto-link
    // ---------------------------------------------------------------

    public function testItLinksGoogleToAnExistingLocalAccount(): void
    {
        // Arrange: a normal password account, not yet verified.
        UserFactory::createOne([
            'email' => 'local@orbly.test',
            'password' => 'secret1234',
            'emailVerified' => false,
        ]);

        $this->signInWithGoogle([
            'sub' => 'google-link-me',
            'email' => 'local@orbly.test',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertFalse($this->json()['isNew']);

        // No second account was created.
        UserFactory::assert()->count(1);

        $user = $this->userRepository()->findOneByEmail('local@orbly.test');

        // Google is now attached...
        self::assertSame('google-link-me', $user->getGoogleId());

        // ...and Google's proof upgraded them to verified. In Phase 2c this
        // means signing in with Google skips the confirmation email.
        self::assertTrue($user->isEmailVerified());

        // Their password is untouched — they now have TWO ways to sign in.
        self::assertTrue($user->hasPassword());
    }

    public function testTheLinkedUserCanStillLogInWithTheirPassword(): void
    {
        UserFactory::createOne([
            'email' => 'both@orbly.test',
            'password' => 'secret1234',
        ]);

        $this->signInWithGoogle(['sub' => 'google-both', 'email' => 'both@orbly.test']);
        self::assertResponseIsSuccessful();

        // Linking must not have broken password login.
        $this->postJson('/api/login', [
            'email' => 'both@orbly.test',
            'password' => 'secret1234',
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $this->json());
    }

    // ---------------------------------------------------------------
    // Rejections
    // ---------------------------------------------------------------

    public function testItRejectsAnInvalidToken(): void
    {
        // The fake verifier throws on this exact string.
        $this->postJson('/api/auth/google', ['idToken' => 'invalid-token']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        UserFactory::assert()->count(0);
    }

    public function testItRejectsAnUnverifiedGoogleEmail(): void
    {
        // THE security test. Without this guard, someone could create a
        // Google account claiming an address they don't own and auto-link
        // into the matching Orbly account.
        $this->signInWithGoogle([
            'sub' => 'google-sneaky',
            'email' => 'victim@orbly.test',
            'email_verified' => false,
        ]);

        // 403, not 401: the token is real, we are refusing on policy.
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Nothing was created and nothing was linked.
        UserFactory::assert()->count(0);
    }

    public function testAnUnverifiedGoogleEmailCannotHijackAnExistingAccount(): void
    {
        // The attack this all exists to prevent, spelled out.
        UserFactory::createOne([
            'email' => 'victim@orbly.test',
            'password' => 'secret1234',
        ]);

        $this->signInWithGoogle([
            'sub' => 'attacker-google-id',
            'email' => 'victim@orbly.test',
            'email_verified' => false,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // The victim's account was NOT linked to the attacker's Google ID.
        $victim = $this->userRepository()->findOneByEmail('victim@orbly.test');
        self::assertNull($victim->getGoogleId());
    }

    public function testItRejectsAMissingIdToken(): void
    {
        $this->postJson('/api/auth/google', []);

        // 422 from the DTO's #[Assert\NotBlank] — the controller never ran.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ---------------------------------------------------------------
    // The password-less account edge case
    // ---------------------------------------------------------------

    public function testAGoogleOnlyUserCannotLogInWithAPassword(): void
    {
        // This account has password = NULL. Symfony must reject the login
        // cleanly with a 401, not blow up on a null hash. This is the test
        // that justifies making the column nullable.
        UserFactory::new()->google()->create(['email' => 'googleonly@orbly.test']);

        $this->postJson('/api/login', [
            'email' => 'googleonly@orbly.test',
            'password' => 'anything',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Posts to the Google endpoint. Because the container swapped in
     * FakeGoogleIdTokenVerifier for the test environment, the "token" is
     * simply the claims as JSON — whatever we put here is what the
     * application sees as verified Google output.
     *
     * @param array<string, mixed> $claims
     */
    private function signInWithGoogle(array $claims): void
    {
        $this->postJson('/api/auth/google', ['idToken' => json_encode($claims)]);
    }

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

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function userRepository(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }
}
