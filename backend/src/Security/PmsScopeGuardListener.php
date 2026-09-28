<?php

namespace App\Security;

use Rocket\Core\Security\ApplicationUser;
use Rocket\Core\Security\ScopeGuardListener;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * rocket-core only lets an application that does not impersonate anyone call GET /api/me. PMS opens its own business
 * endpoints (properties, bookings, pricing, conversation, timeline, planning run, cleanings and their link, and the Rocket Place proxies) to such applications,
 * so a client (e.g. LoussaHousing) can drive PMS server-to-server; every other endpoint (users, applications,
 * connectors and their secrets, place links, settings…) stays guarded by rocket-core's listener.
 */
#[AsDecorator(ScopeGuardListener::class)]
final class PmsScopeGuardListener
{
    private const APPLICATION_PATTERN = '#^/api/(timeline|planning/run|place-links|properties(/[^/]+(/(bookings(/[^/]+/(pricing|conversation|guest-link(/send)?|emails(/[^/]+)?))?|welcome-book(/(stats|rotate))?|bilan(\.csv)?|expenses(/import)?|timeline|locks|codes|domotique|access-grants(/[^/]+/(send|revoke))?|documents(/.*)?|stock(/[^/]+)?|cleanings(/[^/]+/link)?))?)?)$#';

    public function __construct(
        #[AutowireDecorated] private readonly ScopeGuardListener $inner,
        private readonly Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->security->getUser() instanceof ApplicationUser
            && preg_match(self::APPLICATION_PATTERN, $event->getRequest()->getPathInfo())) {
            return;
        }

        ($this->inner)($event);
    }
}
