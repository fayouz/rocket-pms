<?php

namespace App\WelcomeBook;

use App\Code\AccessCodePlanner;
use App\Entity\WelcomeBook;
use App\Lodgify\Booking;
use App\Place\PlaceClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What the public pages show. Guest page: the book, personalised with the guest's FIRST NAME only, the stay dates and
 * times, and the keypad code only once the host has sent it to the lock (Rocket Place grant with externalRef = booking
 * id and status "created"): never a planned-but-unsent code. TV screen: the book minus private access details, the
 * first name and checkout of the stay in progress, and the date of the next arrival. No other personal data.
 */
final class WelcomeBookViews
{
    /** Guest link opens 2 days before arrival and closes 1 day after departure (dates in PMS_TIMEZONE). */
    public const OPENS_DAYS_BEFORE = 2;
    public const CLOSES_DAYS_AFTER = 1;

    public function __construct(
        private readonly AccessCodePlanner $planner,
        private readonly PlaceClient $place,
    ) {
    }

    /** @return array{from: string, until: string} */
    public static function window(Booking $b): array
    {
        return [
            'from' => (new \DateTimeImmutable($b->arrival))->modify('-'.self::OPENS_DAYS_BEFORE.' days')->format('Y-m-d'),
            'until' => (new \DateTimeImmutable($b->departure))->modify('+'.self::CLOSES_DAYS_AFTER.' days')->format('Y-m-d'),
        ];
    }

    public function booking(WelcomeBook $book, int $bookingId): ?Booking
    {
        foreach ($this->planner->bookingsOf($book->getProperty()) as $b) {
            if ($b->id === $bookingId) {
                return $b;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function guest(WelcomeBook $book, Booking $b, string $lang = WelcomeBook::DEFAULT_LANGUAGE): array
    {
        $property = $book->getProperty();

        return [
            'property' => $property->getName(),
            'lang' => $lang, 'languages' => $book->getLanguages(), 'style' => self::publicStyle($book),
            'latitude' => $property->getLatitude(), 'longitude' => $property->getLongitude(),
            'guest' => ['firstName' => self::firstName($b->guest), 'arrival' => $b->arrival, 'departure' => $b->departure,
                'checkIn' => $b->checkIn, 'checkOut' => $b->checkOut],
            'access' => $this->sentAccess($book, $b),
            'content' => self::personalise($book->getContentIn($lang), self::firstName($b->guest)),
        ];
    }

    /** @return array<string, mixed> */
    public function tv(WelcomeBook $book, string $lang = WelcomeBook::DEFAULT_LANGUAGE): array
    {
        $property = $book->getProperty();
        $today = $this->planner->today();
        $now = new \DateTimeImmutable();
        $current = null;
        $next = null;
        foreach ($this->planner->bookingsOf($property) as $b) {
            if (!$b->isActive()) {
                continue;
            }
            if ($b->arrival <= $today && $b->departure > $today) {
                $current = $b;
            }
            // Next check-in still to come, today's included (the screen reloads 30 minutes before it)
            if ($this->arrivalAt($b) > $now && (null === $next || $b->arrival < $next->arrival)) {
                $next = $b;
            }
        }
        $content = array_intersect_key($book->getContentIn($lang), array_flip(['welcomeText', 'wifiSsid', 'wifiPassword', 'checkoutInfo', 'houseRules', 'localTips', 'contacts']));

        return [
            'property' => $property->getName(),
            'latitude' => $property->getLatitude(), 'longitude' => $property->getLongitude(),
            'today' => $today,
            'guest' => null === $current ? null : ['firstName' => self::firstName($current->guest), 'departure' => $current->departure, 'checkOut' => $current->checkOut],
            'nextArrival' => $next?->arrival,
            // The kiosk reloads itself 30 minutes before the next check-in, so the new guest is greeted by name
            'nextArrivalAt' => null === $next ? null : $this->arrivalAt($next)->format(\DATE_ATOM),
            'reloadAt' => null === $next ? null : $this->arrivalAt($next)->sub(new \DateInterval('PT30M'))->format(\DATE_ATOM),
            'lang' => $lang, 'languages' => $book->getLanguages(), 'style' => self::publicStyle($book),
            'content' => self::personalise($content, null === $current ? null : self::firstName($current->guest)),
        ];
    }

    /** Check-in time of a booking (its check-in hour, 15:00 by default, PMS_TIMEZONE). */
    public function arrivalAt(Booking $b): \DateTimeImmutable
    {
        return $this->planner->validity($b)[0]->add(new \DateInterval('PT1H'));
    }

    /** Visual customisation for the public pages: the cover is an https URL or, for a Place document, a flag (the page asks the public cover endpoint). @return array<string, mixed> */
    public static function publicStyle(WelcomeBook $book): array
    {
        $style = $book->getStyle();

        return ['accent' => $style['accent'], 'layout' => $style['layout'], 'coverUrl' => $style['coverUrl'], 'documentCover' => null !== $style['coverDocumentRef']];
    }

    public static function firstName(string $guest): string
    {
        $first = preg_split('/\s+/', trim($guest))[0] ?? '';

        return mb_substr('' === $first ? 'voyageur' : $first, 0, 40);
    }

    /** @param array<string, string> $content @return array<string, string> {{guest}} → first name */
    private static function personalise(array $content, ?string $firstName): array
    {
        return array_map(static fn (string $t) => str_replace('{{guest}}', $firstName ?? 'voyageur', $t), $content);
    }

    /** @return array{code: string, validFrom: string, validUntil: string}|null */
    private function sentAccess(WelcomeBook $book, Booking $b): ?array
    {
        $placeId = $book->getProperty()->getPlaceId();
        if (null === $placeId) {
            return null;
        }
        try {
            $grants = $this->place->request('GET', '/api/places/'.$placeId.'/access-grants');
        } catch (HttpException) {
            return null; // Rocket Place unreachable: the page still works, without the code
        }
        foreach ($grants as $g) {
            if (\is_array($g) && (string) ($g['externalRef'] ?? '') === (string) $b->id && 'created' === ($g['status'] ?? null) && \is_string($g['code'] ?? null)) {
                return ['code' => $g['code'], 'validFrom' => (string) $g['validFrom'], 'validUntil' => (string) $g['validUntil']];
            }
        }

        return null;
    }
}
