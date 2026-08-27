<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\BlockRepository;
use App\Service\BlockService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BlockController extends AbstractController
{
    public function __construct(
        private readonly BlockService $service,
        private readonly BlockRepository $blocks,
    ) {
    }

    #[Route('/api/blocks/{id}', name: 'api_block_create', methods: ['POST'])]
    public function block(User $id): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        try {
            $this->service->block($me, $id);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(
                ['message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse([
            'message' => 'User blocked. Any friendship and pending requests have been removed.',
        ]);
    }

    #[Route('/api/blocks/{id}', name: 'api_block_remove', methods: ['DELETE'])]
    public function unblock(User $id): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        if (!$this->service->unblock($me, $id)) {
            return new JsonResponse(
                ['message' => 'You have not blocked this user.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['message' => 'User unblocked.']);
    }

    #[Route('/api/blocks', name: 'api_block_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        return new JsonResponse([
            'items' => array_map(
                static fn ($b) => [
                    'id' => $b->getBlocked()->getId(),
                    'displayName' => $b->getBlocked()->getDisplayName(),
                    'blockedAt' => $b->getCreatedAt()->format(\DATE_ATOM),
                ],
                $this->blocks->findBlocksBy($me)
            ),
        ]);
    }
}
