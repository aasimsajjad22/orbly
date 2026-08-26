<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    /**
     * This method body is never executed.
     *
     * The route only exists so Symfony's router can match POST /api/login.
     * The "json_login" firewall intercepts the request first and returns
     * the JWT via Lexik's success handler.
     *
     * If you ever DO see this exception, it means the firewall did not pick
     * the request up — usually a check_path or firewall pattern mismatch.
     */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException(
            'This should be handled by the json_login firewall. Check security.yaml.'
        );
    }
}
