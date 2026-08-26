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

        // We build the array by hand so we control EXACTLY what leaves the app.
        // Never json_encode the entity directly — it would expose the password
        // hash and the roles array. In Phase 4 we replace this with the
        // Serializer and #[Groups] attributes, which is the scalable version
        // (Laravel's API Resources).
        return new JsonResponse([
            'id'          => $user->getId(),
            'email'       => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'bio'         => $user->getBio(),
            'roles'       => $user->getRoles(),
            // format() turns the DateTimeImmutable into an ISO-8601 string,
            // which is what every JSON API client expects.
            'createdAt'   => $user->getCreatedAt()->format(\DATE_ATOM),
        ]);
    }
}
