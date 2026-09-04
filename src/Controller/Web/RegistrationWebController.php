<?php

namespace App\Controller\Web;

use App\Entity\User;
use App\Message\SendVerificationEmail;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationWebController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_feed');
        }

        // GET: just show the empty form.
        if (!$request->isMethod('POST')) {
            return $this->render('security/register.html.twig', [
                'errors' => [],
                'values' => [],
            ]);
        }

        // CSRF. The API does not need this because a Bearer token cannot
        // be sent by a cross-site form. A cookie-authenticated form can,
        // so the token is required here.
        if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
            return $this->render('security/register.html.twig', [
                'errors' => ['general' => 'Your session expired. Please try again.'],
                'values' => [],
            ]);
        }

        // Read the fields straight off the request — no Form component.
        $email = strtolower(trim((string) $request->request->get('email')));
        $displayName = trim((string) $request->request->get('displayName'));
        $password = (string) $request->request->get('password');

        // Validate by hand. Keyed by field name so the template can put
        // each message next to its input.
        $errors = [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif ($this->users->findOneByEmail($email) !== null) {
            $errors['email'] = 'An account with this email already exists.';
        }

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 50) {
            $errors['displayName'] = 'Your name must be between 2 and 50 characters.';
        }

        if (mb_strlen($password) < 8) {
            $errors['password'] = 'Your password must be at least 8 characters.';
        }

        if ($errors !== []) {
            // Re-render with the errors AND the values, so the user does
            // not have to retype everything. Never send the password back.
            return $this->render('security/register.html.twig', [
                'errors' => $errors,
                'values' => ['email' => $email, 'displayName' => $displayName],
            ]);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        // Same message the API dispatches — the worker handles both.
        $this->bus->dispatch(new SendVerificationEmail($user->getId()));

        // No auto-login: the hard gate means they cannot sign in until
        // they click the link anyway.
        $this->addFlash('success', 'Account created. Check your email to confirm your address.');

        return $this->redirectToRoute('app_login');
    }
}
