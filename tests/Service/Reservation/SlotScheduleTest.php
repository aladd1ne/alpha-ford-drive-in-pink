<?php

declare(strict_types=1);

namespace App\Tests\Service\Reservation;

use App\Enum\Experience;
use App\Service\Reservation\SlotSchedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Checks the real configuration (config/packages/reservation.yaml) against the brief.
 */
final class SlotScheduleTest extends KernelTestCase
{
    private SlotSchedule $schedule;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->schedule = static::getContainer()->get(SlotSchedule::class);
    }

    public function testEverestRangerFridayRunsUntil1615(): void
    {
        $slots = $this->schedule->slotsFor(Experience::EverestRanger, new \DateTimeImmutable('2026-10-09'));

        self::assertCount(8, $slots);
        self::assertSame('09h00 – 09h30', $slots['09:00']);
        self::assertSame('15h45 – 16h15', end($slots));
    }

    public function testEverestRangerSaturdayStopsAt1400(): void
    {
        $slots = $this->schedule->slotsFor(Experience::EverestRanger, new \DateTimeImmutable('2026-10-10'));

        self::assertCount(5, $slots);
        self::assertSame('12h00 – 12h30', end($slots));
        self::assertArrayNotHasKey('14:15', $slots);
    }

    public function testNoEverestRangerSlotDuringTheLunchBreak(): void
    {
        $slots = $this->schedule->slotsFor(Experience::EverestRanger, new \DateTimeImmutable('2026-10-09'));

        self::assertArrayNotHasKey('12:45', $slots);
        self::assertArrayNotHasKey('13:30', $slots);
        self::assertArrayHasKey('14:15', $slots);
    }

    public function testTerritoryFridayHasHalfHourSlotsWithALunchBreak(): void
    {
        $dates = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $this->schedule->dates(Experience::Territory));
        self::assertSame(['2026-10-23', '2026-10-24'], $dates);

        $slots = $this->schedule->slotsFor(Experience::Territory, new \DateTimeImmutable('2026-10-23'));

        self::assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:30', '14:00', '14:30', '15:00', '15:30'], array_keys($slots));
        self::assertSame('15h30 – 16h00', end($slots));
    }

    public function testTerritorySaturdayRunsUntil1400(): void
    {
        $slots = $this->schedule->slotsFor(Experience::Territory, new \DateTimeImmutable('2026-10-24'));

        self::assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30'], array_keys($slots));
        self::assertSame('13h30 – 14h00', end($slots));
    }

    public function testAllSlotsCoverEveryExperienceSortedByStart(): void
    {
        $starts = array_keys($this->schedule->allSlots());

        self::assertContains('09:45', $starts);
        self::assertContains('13:30', $starts);
        self::assertSame($starts, (static function (array $s): array { sort($s); return $s; })($starts));
    }

    public function testNoSlotsOutsideAnExperienceDates(): void
    {
        self::assertSame([], $this->schedule->slotsFor(Experience::EverestRanger, new \DateTimeImmutable('2026-10-23')));
        self::assertSame([], $this->schedule->slotsFor(Experience::Territory, new \DateTimeImmutable('2026-10-09')));
        self::assertSame([], $this->schedule->slotsFor(Experience::October, new \DateTimeImmutable('2026-10-15')));
    }

    public function testRequestDatesAreOctoberDaysWithoutEvents(): void
    {
        self::assertTrue($this->schedule->isRequestDate(new \DateTimeImmutable('2026-10-14')));
        self::assertTrue($this->schedule->isRequestDate(new \DateTimeImmutable('2026-10-30')));

        foreach (['2026-10-09', '2026-10-10', '2026-10-23', '2026-10-24', '2026-09-30', '2026-11-02'] as $day) {
            self::assertFalse($this->schedule->isRequestDate(new \DateTimeImmutable($day)), $day);
        }
    }

    public function testRequestDatesExcludeWeekendsAndClosedDays(): void
    {
        foreach (['2026-10-03', '2026-10-04', '2026-10-17', '2026-10-31', '2026-10-15'] as $day) {
            self::assertFalse($this->schedule->isRequestDate(new \DateTimeImmutable($day)), $day);
        }
    }

    public function testCapacityIsConfigured(): void
    {
        self::assertSame(1, $this->schedule->capacity(Experience::EverestRanger));
        self::assertSame(3, $this->schedule->capacity(Experience::Territory));
        self::assertSame(0, $this->schedule->capacity(Experience::October));
    }
}
