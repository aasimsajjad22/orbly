<?php

namespace App\Twig\Components;

use App\Entity\Post;
use App\Entity\User;
use App\Repository\PostLikeRepository;
use App\Repository\PostRepository;
use App\Service\PostInteractionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A like button that updates itself without a page reload.
 *
 * #[AsLiveComponent] registers it and gives it a route the browser can
 * post to. DefaultActionTrait supplies the plumbing for re-rendering.
 */
#[AsLiveComponent]
final class LikeButton
{
    use DefaultActionTrait;

    /**
     * #[LiveProp] means this value is part of the component's state and
     * survives between requests — it is serialized into the HTML and sent
     * back on every interaction.
     *
     * writable: false (the default) means the browser CANNOT change it.
     * That matters: if it were writable, someone could edit the DOM and
     * make the component act on a different post.
     *
     * For an entity, only the ID is serialized; Live Components re-fetch
     * it from Doctrine on the next request. So this is not a stale
     * snapshot — same reasoning as putting IDs in Messenger messages.
     */
    #[LiveProp]
    public Post $post;

    public function __construct(
        private readonly PostInteractionService $interactions,
        private readonly PostLikeRepository $likes,
        private readonly PostRepository $posts,
        private readonly Security $security,
    ) {
    }

    /**
     * #[LiveAction] exposes this method to the browser. Clicking the
     * button posts here, the method runs, and the component re-renders.
     *
     * Note it is a normal PHP method with injected services — the
     * component is a service like any other.
     */
    #[LiveAction]
    public function toggle(): void
    {
        /** @var User $user */
        $user = $this->security->getUser();

        // Reuse the SAME service the API uses. The like/unlike logic —
        // the transaction, the atomic counter update, the idempotency —
        // is written once and shared.
        if ($this->isLiked()) {
            $this->interactions->unlike($this->post, $user);
        } else {
            $this->interactions->like($this->post, $user);
        }

        // The service ran a DQL UPDATE on like_count, which changes the
        // database without touching this in-memory Post object. Without
        // this refresh the button would re-render with the old count.
        $this->posts->refresh($this->post);
    }

    /**
     * A plain method, callable from the template as this.liked.
     *
     * Not a LiveProp: it is derived, not state. Computing it on each
     * render means it can never drift from the database.
     */
    public function isLiked(): bool
    {
        /** @var User $user */
        $user = $this->security->getUser();

        return $this->likes->findOneByPostAndUser($this->post, $user) !== null;
    }
}
