<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Web (session) auth screens.
 *
 * Separate namespace from the API controllers so the two surfaces stay
 * visibly distinct — App\Controller\Web renders HTML, App\Controller
 * returns JSON.
 */
class SecurityWebController extends AbstractController
{
    /**
     * GET renders the form. POST is intercepted by the form_login
     * firewall before this method runs — exactly like /api/login, where
     * the stub controller never executes on a real login attempt.
     *
     * So this body only ever runs for GET.
     */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Already signed in? Do not show the form again.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_feed');
        }

        // AuthenticationUtils reads the last failure and the last
        // submitted username out of the session, so we can re-render the
        // form with an error and a prefilled field.
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Never executed. The logout listener intercepts this route and clears
     * the session. The method exists only so the router has something to
     * match — the same reason the API login stub exists.
     */
    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key in security.yaml.');
    }
}
