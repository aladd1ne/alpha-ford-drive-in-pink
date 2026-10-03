<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class StatsService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function getOverviewStats(): array
    {
        $now = new \DateTime();

        $totalBookings = (int) $this->em->createQuery(
            'SELECT COUNT(b.id) FROM App\Entity\Booking b WHERE b.archived = 0'
        )->getSingleScalarResult();

        $monthStart = new \DateTime('first day of this month 00:00:00');
        $thisMonthBookings = (int) $this->em->createQuery(
            'SELECT COUNT(b.id) FROM App\Entity\Booking b WHERE b.archived = 0 AND b.createdAt >= :start'
        )->setParameter('start', $monthStart)->getSingleScalarResult();

        $totalRevenue = (float) ($this->em->createQuery(
            'SELECT COALESCE(SUM(b.totalPrice), 0) FROM App\Entity\Booking b WHERE b.archived = 0'
        )->getSingleScalarResult() ?? 0);

        $depositsCount = (int) $this->em->createQuery(
            "SELECT COUNT(b.id) FROM App\Entity\Booking b WHERE b.archived = 0 AND b.status IN ('confirmed', 'completed')"
        )->getSingleScalarResult();

        $byStatusRaw = $this->em->createQuery(
            'SELECT b.status, COUNT(b.id) AS cnt FROM App\Entity\Booking b WHERE b.archived = 0 GROUP BY b.status'
        )->getResult();
        $byStatus = [];
        foreach ($byStatusRaw as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }

        $byCategory = $this->em->createQuery(
            'SELECT c.name, COUNT(b.id) AS cnt FROM App\Entity\Booking b JOIN b.category c WHERE b.archived = 0 GROUP BY c.id, c.name ORDER BY cnt DESC'
        )->getResult();

        return [
            'totalBookings'    => $totalBookings,
            'thisMonthBookings' => $thisMonthBookings,
            'totalRevenue'     => $totalRevenue,
            'depositsCollected' => $depositsCount * 50,
            'byStatus'         => $byStatus,
            'byCategory'       => $byCategory,
        ];
    }

    public function getChartData(): array
    {
        $labels = [];
        $bookingCounts = [];
        $revenueAmounts = [];

        for ($i = 11; $i >= 0; $i--) {
            $d     = new \DateTime("-{$i} months");
            $start = new \DateTime($d->format('Y-m-01 00:00:00'));
            $end   = (clone $start)->modify('+1 month');

            $labels[] = $d->format('M Y');

            $cnt = (int) $this->em->createQuery(
                'SELECT COUNT(b.id) FROM App\Entity\Booking b WHERE b.archived = 0 AND b.createdAt >= :s AND b.createdAt < :e'
            )->setParameters(['s' => $start, 'e' => $end])->getSingleScalarResult();

            $rev = (float) ($this->em->createQuery(
                'SELECT COALESCE(SUM(b.totalPrice), 0) FROM App\Entity\Booking b WHERE b.archived = 0 AND b.createdAt >= :s AND b.createdAt < :e'
            )->setParameters(['s' => $start, 'e' => $end])->getSingleScalarResult() ?? 0);

            $bookingCounts[]  = $cnt;
            $revenueAmounts[] = round($rev, 2);
        }

        return [
            'labels'   => $labels,
            'bookings' => $bookingCounts,
            'revenue'  => $revenueAmounts,
        ];
    }

    public function getMostPopularExtras(): array
    {
        $rows = $this->em->createQuery(
            'SELECT b.selectedExtras FROM App\Entity\Booking b WHERE b.archived = 0 AND b.selectedExtras IS NOT NULL'
        )->getResult();

        $counts = [];
        foreach ($rows as $row) {
            foreach ($row['selectedExtras'] ?? [] as $extra) {
                $name = $extra['name'] ?? 'N/A';
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_slice($counts, 0, 8, true);
    }
}
