<?php

declare(strict_types=1);

namespace App\Tests\Service\Reservation;

use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Service\Reservation\SlotAvailability;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class SlotAvailabilityTest extends KernelTestCase
{
    use DatabaseTrait;

    private SlotAvailability $availability;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->availability = static::getContainer()->get(SlotAvailability::class);
    }

    public function testSlotsAreIndependentPerDateVehicleAndSlot(): void
    {
        $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');

        $friday = new \DateTimeImmutable('2026-10-09');
        self::assertSame(0, $this->availability->remaining(Experience::EverestRanger, $friday, Vehicle::RangerRaptor, '09:00'));
        // Same slot, other vehicle.
        self::assertSame(1, $this->availability->remaining(Experience::EverestRanger, $friday, Vehicle::RangerWildtrak, '09:00'));
        // Same vehicle, other slot.
        self::assertSame(1, $this->availability->remaining(Experience::EverestRanger, $friday, Vehicle::RangerRaptor, '09:45'));
        // Same vehicle and slot, other day.
        self::assertSame(1, $this->availability->remaining(Experience::EverestRanger, new \DateTimeImmutable('2026-10-10'), Vehicle::RangerRaptor, '09:00'));
    }

    public function testTerritoryDaysAreIndependent(): void
    {
        $this->createBooking(Experience::Territory, '2026-10-23', Vehicle::Territory, '09:00');

        self::assertSame(0, $this->availability->remaining(Experience::Territory, new \DateTimeImmutable('2026-10-23'), Vehicle::Territory, '09:00'));
        self::assertSame(1, $this->availability->remaining(Experience::Territory, new \DateTimeImmutable('2026-10-24'), Vehicle::Territory, '09:00'));
    }

    public function testSlotsAfterClosingTimeAreNotOffered(): void
    {
        $saturday = new \DateTimeImmutable('2026-10-10');

        self::assertSame(1, $this->availability->remaining(Experience::EverestRanger, $saturday, Vehicle::EverestXlt, '13:30'));
        self::assertSame(0, $this->availability->remaining(Experience::EverestRanger, $saturday, Vehicle::EverestXlt, '14:15'));
        self::assertArrayNotHasKey('14:15', $this->availability->remainingMap(Experience::EverestRanger)['2026-10-10']['everest_xlt']);
    }

    public function testExperienceIsFullOnlyWhenEverySlotIsTaken(): void
    {
        $this->fillExperience(Experience::Territory, except: ['2026-10-24|13:30']);
        self::assertFalse($this->availability->isFull(Experience::Territory));

        $this->createBooking(Experience::Territory, '2026-10-24', Vehicle::Territory, '13:30');
        self::assertTrue($this->availability->isFull(Experience::Territory));

        // The other experiences are not affected.
        self::assertFalse($this->availability->isFull(Experience::EverestRanger));
        self::assertFalse($this->availability->isFull(Experience::October));
    }

    public function testStartedSlotsAreNoLongerBookable(): void
    {
        /** @var MockClock $clock */
        $clock = static::getContainer()->get('clock');
        $clock->modify('2026-10-09 10:00:00');

        $map = $this->availability->remainingMap(Experience::EverestRanger);

        self::assertArrayNotHasKey('09:00', $map['2026-10-09']['ranger_raptor']);
        self::assertArrayNotHasKey('09:45', $map['2026-10-09']['ranger_raptor']);
        self::assertSame(1, $map['2026-10-09']['ranger_raptor']['10:30']);
        self::assertTrue($this->availability->hasStarted(new \DateTimeImmutable('2026-10-09'), '09:45'));
    }
}
