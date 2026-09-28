<?php

namespace App\Repository;

use App\Entity\WelcomeBook;
use App\Entity\WelcomeBookVisit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WelcomeBookVisit> */
class WelcomeBookVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WelcomeBookVisit::class);
    }

    /** +1 on the counter of the link for the day (atomic upsert, concurrent visits never lose a count). */
    public function record(WelcomeBook $book, string $link, int $bookingId, string $day): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO welcome_book_visit (book_id, link, booking_id, day, count) VALUES (:book, :link, :booking, :day, 1)
             ON CONFLICT (book_id, link, booking_id, day) DO UPDATE SET count = welcome_book_visit.count + 1',
            ['book' => $book->getId()->toRfc4122(), 'link' => $link, 'booking' => $bookingId, 'day' => $day],
        );
    }

    /** @return list<array{link: string, bookingId: int, day: string, count: int}> counters since $since, newest day first */
    public function since(WelcomeBook $book, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('v')
            ->andWhere('v.book = :book')->andWhere('v.day >= :since')
            ->setParameter('book', $book)->setParameter('since', $since, 'date_immutable')
            ->orderBy('v.day', 'DESC')->addOrderBy('v.link', 'ASC')->addOrderBy('v.bookingId', 'ASC')
            ->getQuery()->getResult();

        return array_map(static fn (WelcomeBookVisit $v) => ['link' => $v->getLink(), 'bookingId' => $v->getBookingId(), 'day' => $v->getDay()->format('Y-m-d'), 'count' => $v->getCount()], $rows);
    }
}
