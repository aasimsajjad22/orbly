<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class VerifyEmailController extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * The link target from the email.
     *
     * GET, because the user clicks it in a mail client. That does break
     * REST purity — a GET changes state here — but the alternative is
     * asking people to POST from their inbox, which they can't. Every
     * real product does it this way.
     */
    #[Route('/api/verify-email', name: 'api_verify_email', methods: ['GET'])]
    public function verify(Request $request): JsonResponse
    {
        // The signed URL carries ?id=... alongside the signature. We need
        // the id to load the user BEFORE we can validate, because the
        // signature is computed from that user's email.
        $id = $request->query->get('id');

        if ($id === null) {
            return new JsonResponse(
                ['message' => 'Invalid verification link.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user = $this->users->find($id);

        if ($user === null) {
            // Same flat message as a bad signature — don't reveal whether
            // this user id exists.
            return new JsonResponse(
                ['message' => 'Invalid verification link.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Already done? Say so plainly. Clicking twice is normal user
        // behaviour, not an error worth a scary message.
        if ($user->isEmailVerified()) {
            return new JsonResponse(['message' => 'Your email is already verified.']);
        }

        try {
            // Recomputes the HMAC and compares. Throws on tampering,
            // expiry, or an email that no longer matches.
            $this->emailVerifier->confirm($request, $user);
        } catch (VerifyEmailExceptionInterface $e) {
            // getReason() gives a safe, user-facing string from the bundle,
            // e.g. "The link to verify your email has expired."
            return new JsonResponse(
                ['message' => $e->getReason()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse([
            'message' => 'Email verified. You can now sign in.',
        ]);
    }
}
