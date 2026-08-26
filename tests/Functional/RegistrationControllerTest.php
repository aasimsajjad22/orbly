<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegisterCreatesANewUser(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'jane@example.com',
                'password' => 'super-secret-123',
                'displayName' => 'Jane Doe',
            ]),
        );

        self::assertResponseStatusCodeSame(201);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('jane@example.com', $data['email']);
        self::assertArrayNotHasKey('password', $data); // never leak the hash in the response

        // Confirm it was actually persisted, and the password got hashed (not stored in plain text).
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'jane@example.com']);

        self::assertNotNull($user);
        self::assertNotSame('super-secret-123', $user->getPassword());
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $client = static::createClient();
        $payload = json_encode([
            'email' => 'duplicate@example.com',
            'password' => 'super-secret-123',
            'displayName' => 'First User',
        ]);

        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(201);

        // Same email again — should be rejected, not create a second row.
        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(422);
    }
}
