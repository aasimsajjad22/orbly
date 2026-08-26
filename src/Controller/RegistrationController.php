<?php

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// Single-action "invokable" controller — same idea as __invoke controllers in Laravel.
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $user,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EmailVerifier $emailVerifier,   // ← new
    ){
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] RegisterRequest $payload
    ): JsonResponse
    {
        if ($this->user->findOneByEmail($payload->email) !== null) {
            return new JsonResponse(['message' => 'An account with this email already exists.'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($payload->email);
        $user->setDisplayName($payload->displayName);
        $user->setBio($payload->bio);
        $user->setPassword($this->hasher->hashPassword($user, $payload->password));
        $this->em->persist($user);
        $this->em->flush();

        // Must come AFTER flush() — the signed URL needs the user's id,
        // which only exists once the INSERT has run.
        $this->emailVerifier->sendVerificationEmail($user);

        return new JsonResponse([
            'id'          => $user->getId(),
            'email'       => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
        ], Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_me')]
        );
    }

}
