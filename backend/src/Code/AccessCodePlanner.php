<?php

namespace App\Code;

use App\Entity\AccessCode;
use App\Entity\SmartLock;
use App\Lock\LockProviderRegistry;
use App\Lodgify\Booking;
use App\Lodgify\LodgifyClient;
use App\Repository\AccessCodeRepository;
use App\Repository\SmartLockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keypad codes: planned in the database for every upcoming stay of a property that has a lock, then sent to Nuki only
 * on a user's click. The code opens 1 h before check-in and closes 1 h after check-out (local time of the properties).
 * A stay that has started is never touched again.
 */
final class AccessCodePlanner
{
    private const DEFAULT_CHECKIN = '15:00';
    private const DEFAULT_CHECKOUT = '11:00';
    private const MARGIN = 'PT1H';

    public function __construct(
        private readonly LodgifyClient $lodgify,
        private readonly LockProviderRegistry $lockProviders,
        private readonly SmartLockRepository $locks,
        private readonly AccessCodeRepository $codes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly string $timezone,
    ) {
    }

    /** Opening and closing of the code of a booking. @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public function validity(Booking $b): array
    {
        $tz = new \DateTimeZone($this->timezone);
        $from = new \DateTimeImmutable($b->arrival.' '.($b->checkIn ?? self::DEFAULT_CHECKIN), $tz);
        $until = new \DateTimeImmutable($b->departure.' '.($b->checkOut ?? self::DEFAULT_CHECKOUT), $tz);

        return [$from->sub(new \DateInterval(self::MARGIN)), $until->add(new \DateInterval(self::MARGIN))];
    }

    /** Plans (or re-dates, while not sent) the codes of all upcoming active stays whose property has a lock. */
    public function plan(): void
    {
        $today = $this->today();
        $lockByLodgify = [];
        foreach ($this->locks->findAll() as $lock) {
            $id = $lock->getProperty()?->getLodgifyPropertyId();
            if (null !== $id && !isset($lockByLodgify[$id])) {
                $lockByLodgify[$id] = $lock;
            }
        }
        foreach ($this->lodgify->bookings() as $b) {
            $lock = $lockByLodgify[$b->propertyId] ?? null;
            if (null === $lock || !$b->isActive() || $b->arrival <= $today) {
                continue;
            }
            [$from, $until] = $this->validity($b);
            $code = $this->codes->find($b->id);
            if (null === $code) {
                $this->em->persist(new AccessCode($b->id, $lock, $this->newCode($lock), $from, $until));
            } elseif (AccessCode::CREATED !== $code->getStatus() && ($code->getValidFrom() != $from || $code->getValidUntil() != $until)) {
                $code->setValidity($from, $until);
            }
        }
        $this->em->flush();
    }

    /** A code sent to Nuki whose booking dates changed since: it must be redone by hand in Nuki. */
    public function isOutdated(AccessCode $code, Booking $b): bool
    {
        [$from, $until] = $this->validity($b);

        return AccessCode::CREATED === $code->getStatus() && ($code->getValidFrom() != $from || $code->getValidUntil() != $until);
    }

    /** Writes the code to the Nuki lock (explicit user action). */
    public function send(int $bookingId): AccessCode
    {
        $this->plan();
        $code = $this->codes->find($bookingId) ?? throw new HttpException(404, $this->translator->trans('booking.unknown'));
        if (AccessCode::CREATED === $code->getStatus()) {
            throw new HttpException(409, $this->translator->trans('code.already_created'));
        }
        $b = $this->lodgify->booking($bookingId);
        if (null === $b || $b->arrival <= $this->today()) {
            throw new HttpException(409, $this->translator->trans('code.started'));
        }
        try {
            $this->lockProviders->codeProviderFor($code->getLock())->createCode($code->getLock(), $code->getCode(), 'LH-'.$bookingId.' '.$b->guest, $code->getValidFrom(), $code->getValidUntil());
        } catch (HttpException $e) {
            $code->markError($e->getMessage());
            $this->em->flush();
            throw $e;
        }
        $code->markCreated($this->clock->now());
        $this->em->flush();

        return $code;
    }

    public function today(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d');
    }

    /** 6 digits without 0, not starting with 12 (Nuki keypad rules), unique on the lock. */
    private function newCode(SmartLock $lock): string
    {
        $taken = array_map(static fn (AccessCode $c) => $c->getCode(), $this->codes->findBy(['lock' => $lock]));
        do {
            $code = '';
            for ($i = 0; $i < 6; ++$i) {
                $code .= (string) random_int(1, 9);
            }
        } while (str_starts_with($code, '12') || \in_array($code, $taken, true));

        return $code;
    }
}
