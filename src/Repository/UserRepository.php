<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Find one user by email, case-insensitively.
     *
     * We lowercase emails in User::setEmail(), but data can arrive from
     * anywhere (imports, old rows), so we lowercase on both sides to be safe.
     */
    public function findOneByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')   // 'u' is the alias, like SQL's "FROM users u"
        ->andWhere('LOWER(u.email) = :email') // :email is a bound parameter — never string-concat user input
        ->setParameter('email', strtolower(trim($email)))
            ->getQuery()                         // builds the DQL query object
            ->getOneOrNullResult();              // returns a User, or null. Throws if more than one row matches.
    }

    /**
     * Search users by display name or email — used later by "find friends".
     *
     * @return User[]
     */
    public function search(string $term, int $limit = 20): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.displayName) LIKE :term OR LOWER(u.email) LIKE :term')
            ->setParameter('term', '%'.strtolower(trim($term)).'%')
            ->orderBy('u.displayName', 'ASC')
            ->setMaxResults($limit)              // SQL LIMIT — same as Laravel's ->take()
            ->getQuery()
            ->getResult();                       // returns an array of User objects
    }

    /**
     * Called automatically by Symfony when a user logs in and their password
     * hash is out of date (e.g. you raised the bcrypt cost). Symfony re-hashes
     * and hands us the new hash to store. Laravel has no direct equivalent.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);

        // getEntityManager() is inherited. persist() is not needed here because
        // the object is already managed by Doctrine (it was loaded from the DB).
        $this->getEntityManager()->flush();
    }

}
