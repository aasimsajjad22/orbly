<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;


class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // getUser() is a shortcut on AbstractController. Under the hood it is:
        //     $this->container->get('security.token_storage')->getToken()?->getUser()
        // This is the box the whole firewall chain exists to fill. By the time
        // this line runs, Lexik's JWTAuthenticator has already: extracted the
        // Bearer token, verified its RS256 signature against public.pem, checked
        // "exp", and re-loaded this User row from Postgres via EntityUserProvider.
        $user = $this->getUser();

        // Purely for static analysis + IDE autocomplete. getUser() is typed as
        // UserInterface|null, so PHPStan does not know about getDisplayName().
        // This can never actually be false: access_control already rejected
        // anonymous requests with a 401 before reaching us.
        \assert($user instanceof User);

        // json() is a shortcut on AbstractController: it serializes the
        // object and wraps it in a JsonResponse.
        //
        // Third argument = headers. Fourth = serializer context, where the
        // groups go. Only properties carrying one of these groups appear.
        return $this->json($user, 200, [], [
            'groups' => ['user:private'],
        ]);
    }
}
