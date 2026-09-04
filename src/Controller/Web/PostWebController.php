<?php

namespace App\Controller\Web;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\PostVisibility;
use App\Repository\SubscriptionRepository;
use App\Security\Voter\ProFeatureVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PostWebController extends AbstractController
{
    private const FREE_POST_LIMIT = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/posts', name: 'app_post_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        if (!$this->isCsrfTokenValid('post', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');

            return $this->redirectToRoute('app_feed');
        }

        $content = trim((string) $request->request->get('content'));

        if ($content === '') {
            $this->addFlash('error', 'Your post cannot be empty.');

            return $this->redirectToRoute('app_feed');
        }

        // Same Pro gate as the API, using the same Voter.
        $limit = $this->isGranted(ProFeatureVoter::ACCESS) ? 10000 : self::FREE_POST_LIMIT;

        if (mb_strlen($content) > $limit) {
            $this->addFlash('error', sprintf('Posts are limited to %d characters.', $limit));

            return $this->redirectToRoute('app_feed');
        }

        // PostVisibility::tryFrom returns null for an unrecognised value,
        // so a tampered form falls back to public rather than erroring.
        $visibility = PostVisibility::tryFrom(
            (string) $request->request->get('visibility')
        ) ?? PostVisibility::Public;

        $post = new Post($me, $content, $visibility);

        $this->em->persist($post);
        $this->em->flush();

        // Redirect after POST — the standard pattern that stops a browser
        // refresh from re-submitting. Turbo makes the redirect feel
        // instant, so there is no page flash.
        return $this->redirectToRoute('app_feed');
    }
}
