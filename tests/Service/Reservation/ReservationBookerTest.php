<?php

declare(strict_types=1);

namespace App\Tests\Service\Reservation;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Repository\ReservationRepository;
use App\Service\Reservation\ReservationBooker;
use App\Service\Reservation\SlotSchedule;
use App\Service\Reservation\SlotUnavailableException;
use App\Tests\DatabaseTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReservationBookerTest extends KernelTestCase
{
    use DatabaseTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testBookingTakesTheFirstSeat(): void
    {
        $reservation = $this->reservation(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');

        $this->booker()->book($reservation);

        self::assertNotNull($reservation->getId());
        self::assertSame(1, $reservation->getSeat());
        self::assertSame('pending', $reservation->getStatus()->value);
    }

    public function testFullSlotIsRefused(): void
    {
        $this->booker()->book($this->reservation(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00'));

        $this->expectException(SlotUnavailableException::class);
        $this->booker()->book($this->reservation(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00'));
    }

    public function testSameSlotForAnotherVehicleIsAccepted(): void
    {
        $this->booker()->book($this->reservation(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00'));

        $other = $this->reservation(Experience::EverestRanger, '2026-10-09', Vehicle::EverestLimited, '09:00');
        $this->booker()->book($other);

        self::assertSame(1, $other->getSeat());
    }

    public function testCapacityIsConfigurable(): void
    {
        $booker = $this->booker($this->scheduleWithCapacity(2));

        $first = $this->reservation(Experience::Territory, '2026-10-23', null, '09:00');
        $second = $this->reservation(Experience::Territory, '2026-10-23', null, '09:00');
        $booker->book($first);
        $booker->book($second);

        self::assertSame([1, 2], [$first->getSeat(), $second->getSeat()]);

        $this->expectException(SlotUnavailableException::class);
        $booker->book($this->reservation(Experience::Territory, '2026-10-23', null, '09:00'));
    }

    public function testDatabaseRejectsTwoBookingsOfTheSameSeat(): void
    {
        $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
    }

    public function testConcurrentBookingFallsBackToTheNextFreeSeat(): void
    {
        // Another request already took seat 1, but this one read the seats before that insert.
        $this->createBooking(Experience::Territory, '2026-10-23', Vehicle::Territory, '09:00');
        $container = static::getContainer();
        $staleRepository = new class($container->get(ManagerRegistry::class)) extends ReservationRepository {
            private bool $stale = true;

            public function findTakenSeats(Experience $experience, \DateTimeImmutable $date, Vehicle $vehicle, string $slot): array
            {
                if ($this->stale) {
                    $this->stale = false;

                    return [];
                }

                return parent::findTakenSeats($experience, $date, $vehicle, $slot);
            }
        };
        $booker = new ReservationBooker($container->get(ManagerRegistry::class), $staleRepository, $this->scheduleWithCapacity(2));

        $reservation = $this->reservation(Experience::Territory, '2026-10-23', null, '09:00');
        $booker->book($reservation);

        self::assertSame(2, $reservation->getSeat());
        self::assertCount(2, $this->entityManager()->getRepository(Reservation::class)->findAll());
    }

    public function testOctoberRequestIsSavedWithoutSeat(): void
    {
        $request = (new Reservation(Experience::October))
            ->setFullName('Client Test')
            ->setPhone('+216 20 000 000')
            ->setEmail('client@example.com')
            ->setDate(new \DateTimeImmutable('2026-10-15'))
            ->setVehicle(Vehicle::Advice);

        $this->booker()->book($request);

        self::assertNotNull($request->getId());
        self::assertNull($request->getSeat());
        self::assertNull($request->getSlot());
    }

    private function booker(?SlotSchedule $schedule = null): ReservationBooker
    {
        $container = static::getContainer();

        return new ReservationBooker(
            $container->get(ManagerRegistry::class),
            $container->get(ReservationRepository::class),
            $schedule ?? $container->get(SlotSchedule::class),
        );
    }

    private function scheduleWithCapacity(int $capacity): SlotSchedule
    {
        $container = static::getContainer();
        $experiences = $container->getParameter('app.reservation.experiences');
        $experiences['territory']['capacity'] = $capacity;

        return new SlotSchedule(
            $container->getParameter('app.reservation.slots'),
            $experiences,
            $container->getParameter('app.reservation.request_month'),
        );
    }

    private function reservation(Experience $experience, string $date, ?Vehicle $vehicle, string $slot): Reservation
    {
        $reservation = (new Reservation($experience))
            ->setFullName('Client Test')
            ->setPhone('+216 20 000 000')
            ->setEmail('client@example.com')
            ->setDate(new \DateTimeImmutable($date))
            ->setSlot($slot);

        return null === $vehicle ? $reservation : $reservation->setVehicle($vehicle);
    }
}
