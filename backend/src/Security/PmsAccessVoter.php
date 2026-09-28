<?php

namespace App\Security;

use Rocket\Core\Security\ApplicationUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * PMS own permissions, on top of rocket-core roles:
 * - PMS_READ: any signed-in user (ROLE_USER) or an application acting on its own behalf;
 * - PMS_MANAGE: an administrator (ROLE_ADMIN) or an application acting on its own behalf.
 * An application token (Bearer rpm_…) without X-Impersonate-User only carries ROLE_APPLICATION: it is the
 * server-to-server client of PMS (e.g. LoussaHousing). An impersonating application keeps the delegated roles of the
 * user (never ROLE_ADMIN), as rocket-core intends, so it is not elevated here either.
 *
 * @extends Voter<string, mixed>
 */
final class PmsAccessVoter extends Voter
{
    public const READ = 'PMS_READ';
    public const MANAGE = 'PMS_MANAGE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::READ === $attribute || self::MANAGE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($token->getUser() instanceof ApplicationUser) {
            return true;
        }

        return $this->security->isGranted(self::READ === $attribute ? 'ROLE_USER' : 'ROLE_ADMIN');
    }
}
