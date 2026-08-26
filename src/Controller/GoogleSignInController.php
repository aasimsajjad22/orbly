<?php

namespace App\Controller;

use App\Dto\GoogleSignInRequest;
use App\Security\Google\GoogleIdTokenVerifier;
use App\Security\Google\GoogleSignInHandler;
use App\Security\Google\InvalidGoogleTokenException;
use App\Security\Google\UnverifiedGoogleEmailException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class GoogleSignInController extends AbstractController
{
    public function __construct(
        // The INTERFACE, not the class. In test the container hands over
        // the fake; in dev/prod the real one. This controller never knows.
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly GoogleSignInHandler $handler,
        // Lexik's token manager — the same service that mints the JWT after
        // a password login. Reusing it means Google users get an identical
        // token, so everything downstream is unchanged.
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
    }

    #[Route('/api/auth/google', name: 'api_auth_google', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] GoogleSignInRequest $payload): JsonResponse
    {
        try {
            // Step 1: is this token genuinely from Google, for OUR app?
            $googleUser = $this->verifier->verify($payload->idToken);

            // Step 2: find, link, or create.
            [$user, $isNew] = $this->handler->handle($googleUser);
        } catch (InvalidGoogleTokenException) {
            // Flat message on purpose — see the exception's docblock.
            return new JsonResponse(
                ['message' => 'Invalid Google token.'],
                Response::HTTP_UNAUTHORIZED,
            );
        } catch (UnverifiedGoogleEmailException) {
            // 403, not 401: we know who they are, we are refusing on policy.
            return new JsonResponse(
                ['message' => 'Your Google email address is not verified.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        // Step 3: mint OUR token. Note we return Google's identity converted
        // into an Orbly session — Google's token is never used again.
        return new JsonResponse(
            [
                'token' => $this->jwtManager->create($user),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'displayName' => $user->getDisplayName(),
                ],
                // Lets a frontend show an onboarding screen on first sign-in.
                'isNew' => $isNew,
            ],
            // 201 when we created an account, 200 when we just logged them in.
            $isNew ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
