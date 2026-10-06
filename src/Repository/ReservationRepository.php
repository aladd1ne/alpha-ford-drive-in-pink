<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Booked seats per slot for one experience, keyed "Y-m-d|vehicle|HH:MM".
     *
     * @return array<string, int>
     */
    public function countBookedSeats(Experience $experience): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.date AS date, r.vehicle AS vehicle, r.slot AS slot, COUNT(r.id) AS booked')
            ->where('r.experience = :experience')
            ->andWhere('r.seat IS NOT NULL')
            ->groupBy('r.date, r.vehicle, r.slot')
            ->setParameter('experience', $experience)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            // Scalar selects may come back hydrated (enum / date) or raw, depending on the platform.
            $date = $row['date'] instanceof \DateTimeInterface ? $row['date'] : new \DateTimeImmutable((string) $row['date']);
            $vehicle = $row['vehicle'] instanceof Vehicle ? $row['vehicle'] : Vehicle::from((string) $row['vehicle']);
            $counts[self::slotKey($date, $vehicle, (string) $row['slot'])] = (int) $row['booked'];
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    public function findTakenSeats(Experience $experience, \DateTimeImmutable $date, Vehicle $vehicle, string $slot): array
    {
        $seats = $this->createQueryBuilder('r')
            ->select('r.seat')
            ->where('r.experience = :experience')
            ->andWhere('r.date = :date')
            ->andWhere('r.vehicle = :vehicle')
            ->andWhere('r.slot = :slot')
            ->andWhere('r.seat IS NOT NULL')
            ->setParameter('experience', $experience)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setParameter('vehicle', $vehicle)
            ->setParameter('slot', $slot)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $seats);
    }

    /**
     * Active (not cancelled or archived) reservations per experience slug, for the back-office dashboard.
     *
     * @return array<string, int>
     */
    public function countByExperience(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.experience AS experience, COUNT(r.id) AS total')
            ->where('r.status NOT IN (:inactive)')
            ->groupBy('r.experience')
            ->setParameter('inactive', ReservationStatus::inactive())
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $experience = $row['experience'] instanceof Experience ? $row['experience'] : Experience::from((string) $row['experience']);
            $counts[$experience->value] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Listing for the back-office, ordered by event date then slot.
     */
    public function createBackOfficeQueryBuilder(?Experience $experience = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.date', 'ASC')
            ->addOrderBy('r.slot', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC');

        if (null !== $experience) {
            $qb->andWhere('r.experience = :experience')->setParameter('experience', $experience);
        }

        return $qb;
    }

    /**
     * Commercial "today" list: scopes a reservation query to one day, test drives still
     * to validate first, then by slot. Works on EasyAdmin's index query (keeps its search
     * and filter conditions).
     */
    public function applyDayScope(QueryBuilder $qb, \DateTimeImmutable $day): QueryBuilder
    {
        $alias = $qb->getRootAliases()[0];

        return $qb
            ->addSelect(sprintf('CASE WHEN %1$s.testDriveStatus = :toValidate AND %1$s.status != :cancelled THEN 0 ELSE 1 END AS HIDDEN validationOrder', $alias))
            ->andWhere(sprintf('%s.date = :day', $alias))
            ->andWhere(sprintf('%s.status != :archived', $alias))
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->setParameter('toValidate', TestDriveStatus::ToValidate)
            ->setParameter('cancelled', ReservationStatus::Cancelled)
            ->setParameter('archived', ReservationStatus::Archived)
            ->orderBy('validationOrder', 'ASC')
            ->addOrderBy(sprintf('%s.slot', $alias), 'ASC')
            ->addOrderBy(sprintf('%s.createdAt', $alias), 'ASC');
    }

    /**
     * Reservations of one day whose test drive is still to be confirmed.
     */
    public function countToValidate(\DateTimeImmutable $day): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.date = :day')
            ->andWhere('r.testDriveStatus = :toValidate')
            ->andWhere('r.status NOT IN (:inactive)')
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->setParameter('toValidate', TestDriveStatus::ToValidate)
            ->setParameter('inactive', ReservationStatus::inactive())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public static function slotKey(\DateTimeInterface $date, Vehicle $vehicle, string $slot): string
    {
        return $date->format('Y-m-d') . '|' . $vehicle->value . '|' . $slot;
    }
}
