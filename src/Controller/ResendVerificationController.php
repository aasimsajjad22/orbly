<?php

namespace App\Controller;

use App\Dto\ResendVerificationRequest;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class ResendVerificationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailVerifier $emailVerifier,
        private readonly LoggerInterface $logger,
        // #[Target] picks a specific limiter by name. The config above
        // creates one service per limiter, so we must say which we want.
        #[Target('verification_email')]
        private readonly RateLimiterFactoryInterface $emailLimiter,
        #[Target('verification_email_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
    ) {
    }

    #[Route('/api/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload] ResendVerificationRequest $payload,
    ): JsonResponse {
        // The response we return no matter what happens below. Same body,
        // same status, whether the email exists, is already verified, or
        // was never registered. This is the anti-enumeration guarantee.
        $genericResponse = new JsonResponse([
            'message' => 'If that address needs verification, we have sent a new link.',
        ]);

        // ---- IP limit first ----
        // create() takes the "key" the limit is counted against.
        $ipLimit = $this->ipLimiter->create($request->getClientIp())->consume();

        if (!$ipLimit->isAccepted()) {
            // 429 Too Many Requests. Unlike the checks below, we DO signal
            // this one — it reveals nothing about any account, and a real
            // client needs to know to back off.
            return new JsonResponse(
                ['message' => 'Too many requests. Please try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        // ---- Per-email limit ----
        // Keyed on the address, so it protects the potential victim even
        // when requests come from many different IPs.
        $emailLimit = $this->emailLimiter->create($payload->email)->consume();

        if (!$emailLimit->isAccepted()) {
            // Note: generic response, NOT a 429. A 429 here would confirm
            // the address exists and has been targeted — enumeration again.
            return $genericResponse;
        }

        $user = $this->users->findOneByEmail($payload->email);

        // Silent no-ops. Each of these would be a different response in a
        // naive implementation, and each difference is an information leak.
        if ($user === null || $user->isEmailVerified()) {
            $this->logger->info('Verification resend requested for unknown or verified address.');

            return $genericResponse;
        }

        $this->emailVerifier->sendVerificationEmail($user);

        return $genericResponse;
    }
}
