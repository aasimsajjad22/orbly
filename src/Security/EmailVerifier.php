<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Owns both halves of email verification: sending the link, and
 * validating it when the user clicks.
 */
final readonly class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $helper,
        private MailerInterface $mailer,
        private EntityManagerInterface $em,
    ) {
    }

    public function sendVerificationEmail(User $user): void
    {
        // generateSignature() builds the signed URL. Arguments:
        //   1. the ROUTE NAME the link should point at
        //   2. the user id  \
        //   3. the email     } both are mixed into the HMAC signature
        // Because the email is part of the signature, changing the address
        // silently invalidates every outstanding link for that user.
        $components = $this->helper->generateSignature(
            'api_verify_email',
            (string) $user->getId(),
            (string) $user->getEmail(),
            // Fourth argument: extra query params. These are appended to the
            // URL AND mixed into the signature, so they can't be tampered
            // with. We need the id in the URL because the controller must
            // load the user before it can validate the signature — and
            // validation requires that user's email.
            ['id' => $user->getId()],
        );

        // TemplatedEmail renders a Twig template as the body.
        $email = new TemplatedEmail()
            ->from(new Address('no-reply@orbly.test', 'Orbly'))
            ->to((string) $user->getEmail())
            ->subject('Confirm your Orbly account')
            ->htmlTemplate('email/verify.html.twig')
            // context() = the variables available inside the template.
            ->context([
                'displayName' => $user->getDisplayName(),
                'signedUrl' => $components->getSignedUrl(),
                'expiresInMinutes' => $components->getExpirationMessageData()['%count%'] ?? 60,
            ]);

        // send() hands the email to the transport from MAILER_DSN.
        // In dev that's Mailpit; in test it's null:// which discards it
        // but still records it for assertions. In Phase 6 we make this
        // async via Messenger so registration doesn't wait on SMTP.
        $this->mailer->send($email);
    }

    /**
     * Validates a clicked link and marks the user verified.
     *
     * @throws \SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface
     *         if the signature is wrong, the link expired, or the email
     *         no longer matches
     */
    public function confirm(Request $request, User $user): void
    {
        // Recomputes the HMAC from the URL and compares. Throws on any
        // mismatch — we never trust the query string on its own.
        $this->helper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),
            (string) $user->getEmail(),
        );

        $user->setEmailVerified(true);

        // The user was loaded by Doctrine so it's already managed —
        // flush() alone writes the UPDATE.
        $this->em->flush();
    }
}
