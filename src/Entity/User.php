<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'This email is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /**
     * The hashed password.
     *
     * NULL means this account has no password at all — it was created via
     * Google and can only sign in that way. Anything reading this must
     * handle null; see getPassword() below and the login guard.
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 50)]
    private ?string $displayName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $bio = null;

    /**
     * Google's "sub" claim — a permanent, unique ID for this Google account.
     *
     * We link on THIS, never on email. Emails can change hands; a Google
     * "sub" never changes and is never reassigned.
     *
     * Unique so two Orbly accounts can't claim the same Google account.
     * Nullable because password-only users don't have one.
     */
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $googleId = null;

    /**
     * Has this email address been proven to belong to the user?
     *
     * Set true immediately for Google sign-ups (Google already proved it).
     * For local sign-ups it stays false until they click the link we email
     * them in Phase 2c. The login firewall will block false in 2c.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $emailVerified = false;

    /**
     * Requests this user SENT.
     *
     * OneToMany is the INVERSE side — it holds no column. mappedBy names the
     * property on FriendRequest that owns the relation ('sender').
     *
     * Collection, not array: Doctrine returns a lazy PersistentCollection
     * that only queries the database when you actually iterate it.
     *
     * @var Collection<int, FriendRequest>
     */
    #[ORM\OneToMany(targetEntity: FriendRequest::class, mappedBy: 'sender')]
    private Collection $sentFriendRequests;

    /**
     * Requests this user RECEIVED. Mirrors the 'recipient' property.
     *
     * @var Collection<int, FriendRequest>
     */
    #[ORM\OneToMany(targetEntity: FriendRequest::class, mappedBy: 'recipient')]
    private Collection $receivedFriendRequests;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        // Collections MUST be initialised here. Forget this and you get
        // "call to a member function on null" the first time you touch
        // them on a newly created (not yet loaded) entity.
        $this->sentFriendRequests = new ArrayCollection();
        $this->receivedFriendRequests = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    /**
     * The unique identifier Symfony uses for this user.
     * Laravel equivalent: the column in config/auth.php the guard looks up.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER'; // everyone is at least a normal user

        return array_values(array_unique($roles));
    }

    /** @return Collection<int, FriendRequest> */
    public function getSentFriendRequests(): Collection
    {
        return $this->sentFriendRequests;
    }

    /** @return Collection<int, FriendRequest> */
    public function getReceivedFriendRequests(): Collection
    {
        return $this->receivedFriendRequests;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;

        return $this;
    }

    /**
     * True when this account can be signed into with a password.
     *
     * A Google-only account returns false. Use this instead of checking
     * getPassword() !== null at call sites — it says WHY, not just WHAT.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Clears any temporary sensitive data held on the object.
     * We store nothing plain-text, so nothing to do.
     */
    public function eraseCredentials(): void
    {
    }
}
