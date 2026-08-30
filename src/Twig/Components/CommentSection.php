<?php

namespace App\Twig\Components;

use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use App\Security\Voter\CommentVoter;
use App\Service\PostInteractionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class CommentSection
{
    use DefaultActionTrait;

    /**
     * Not writable — the browser must not be able to point this component
     * at a different post.
     */
    #[LiveProp]
    public Post $post;

    /**
     * WRITABLE: bound to the textarea, so the browser sends its value up.
     *
     * Anything writable is untrusted input by definition. It is validated
     * in addComment() below exactly as if it arrived in a POST body —
     * because effectively it did.
     */
    #[LiveProp(writable: true)]
    public string $newComment = '';

    /**
     * Show the thread collapsed until asked. Writable so the toggle
     * action can flip it, but harmless if tampered with — the worst case
     * is that a user sees comments they could already see.
     */
    #[LiveProp(writable: true)]
    public bool $expanded = false;

    /** Set by addComment() and shown next to the textarea. */
    public ?string $error = null;

    public function __construct(
        private readonly PostInteractionService $interactions,
        private readonly CommentRepository $comments,
        private readonly PostRepository $posts,
        private readonly Security $security,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * Called on every render, so the list is always read fresh from the
     * database rather than held in a prop. That keeps it correct when
     * someone else comments while this page is open.
     *
     * @return Comment[]
     */
    public function getComments(): array
    {
        if (!$this->expanded) {
            return [];
        }

        return $this->comments->findForPost($this->post, 50);
    }

    #[LiveAction]
    public function toggle(): void
    {
        $this->expanded = !$this->expanded;
    }

    #[LiveAction]
    public function addComment(): void
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $content = trim($this->newComment);

        // Validate the writable prop. It came from the browser, so it
        // gets the same scrutiny as any request body.
        if ($content === '') {
            $this->error = 'Your comment cannot be empty.';

            return;
        }

        if (mb_strlen($content) > 1000) {
            $this->error = 'Comments are limited to 1000 characters.';

            return;
        }

        // Same service the API uses — the transaction and the atomic
        // counter update are written once.
        $this->interactions->comment($this->post, $user, $content);

        // Clear the box and any previous error. Because these are props,
        // the re-render sends the emptied textarea back to the browser.
        $this->newComment = '';
        $this->error = null;

        // Make sure the thread is visible after posting.
        $this->expanded = true;

        // The service ran a DQL UPDATE on comment_count, which does not
        // touch this in-memory Post. Refresh or the count renders stale.
        $this->posts->refresh($this->post);
    }

    /**
     * #[LiveArg] pulls a value the template passed with the action —
     * here, which comment to delete. Note it arrives from the browser,
     * so the Voter check below is not optional.
     */
    #[LiveAction]
    public function deleteComment(#[LiveArg] int $commentId): void
    {
        $comment = $this->comments->find($commentId);

        if ($comment === null) {
            return;
        }

        // THE authorization check. The id came from the DOM and could be
        // any number. CommentVoter allows the comment's author OR the
        // post's author — the same rule the API endpoint uses.
        if (!$this->authorization->isGranted(CommentVoter::DELETE, $comment)) {
            $this->error = 'You cannot delete that comment.';

            return;
        }

        $this->interactions->deleteComment($comment);
        $this->posts->refresh($this->post);
    }

    /**
     * Exposed so the template can decide whether to show a delete button
     * per comment, without duplicating the rule.
     */
    public function canDelete(Comment $comment): bool
    {
        return $this->authorization->isGranted(CommentVoter::DELETE, $comment);
    }
}
