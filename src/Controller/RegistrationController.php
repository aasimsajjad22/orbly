<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// Single-action "invokable" controller — same idea as __invoke controllers in Laravel.
final class RegistrationController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(
        Request $request,
        ValidatorInterface $validator,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        // Validate the RAW payload before touching the database or hashing anything.
        // Assert\Collection validates a plain array directly — no DTO class needed yet.
        $violations = $validator->validate($data, new Assert\Collection([
            'email' => [new Assert\NotBlank(), new Assert\Email()],
            'password' => [new Assert\NotBlank(), new Assert\Length(min: 8)],
            'displayName' => [new Assert\NotBlank(), new Assert\Length(max: 50)],
        ]));

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Simple existence check. Note: there's a small race-condition window here
        // under concurrent requests — the DB's unique index is the real backstop;
        // fine to leave as-is for a learning project.
        $existing = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if (null !== $existing) {
            return new JsonResponse(['errors' => ['email' => 'This email is already registered.']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setDisplayName($data['displayName']);
        // Hash immediately — the plain password never gets stored anywhere.
        $user->setPassword($passwordHasher->hashPassword($user, $data['password']));

        $entityManager->persist($user);
        $entityManager->flush();

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
        ], Response::HTTP_CREATED);
    }
}
