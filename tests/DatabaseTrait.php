<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AdminUser;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Fresh schema per test on the SQLite test database, plus reservation / user factories.
 * Use from a KernelTestCase / WebTestCase after the kernel (or client) is booted.
 */
trait DatabaseTrait
{
    protected function resetDatabase(): void
    {
        $em = $this->entityManager();
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Stores a reservation that holds a seat, bypassing the booking service.
     */
    protected function createBooking(Experience $experience, string $date, ?Vehicle $vehicle, string $slot, int $seat = 1): Reservation
    {
        $reservation = (new Reservation($experience))
            ->setFullName('Client Test')
            ->setPhone('+216 20 000 000')
            ->setEmail('client@example.com')
            ->setDate(new \DateTimeImmutable($date))
            ->setSlot($slot)
            ->assignSeat($seat);

        if (null !== $vehicle) {
            $reservation->setVehicle($vehicle);
        }

        $em = $this->entityManager();
        $em->persist($reservation);
        $em->flush();

        return $reservation;
    }

    /**
     * Stores a back-office user (the password is not usable: log in with loginUser()).
     *
     * @param list<string> $roles
     */
    protected function createUser(string $email, array $roles): AdminUser
    {
        $user = (new AdminUser())->setEmail($email)->setRoles($roles)->setPassword('!');

        $em = $this->entityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Books every slot of an experience for every vehicle, except the ones listed as "Y-m-d|HH:MM".
     *
     * @param list<string> $except
     */
    protected function fillExperience(Experience $experience, array $except = []): void
    {
        $schedule = static::getContainer()->get(\App\Service\Reservation\SlotSchedule::class);
        $em = $this->entityManager();

        foreach ($schedule->dates($experience) as $date) {
            foreach (array_keys($schedule->slotsFor($experience, $date)) as $slot) {
                if (\in_array($date->format('Y-m-d') . '|' . $slot, $except, true)) {
                    continue;
                }
                foreach ($experience->vehicles() as $vehicle) {
                    $em->persist((new Reservation($experience))
                        ->setFullName('Client Test')
                        ->setPhone('+216 20 000 000')
                        ->setEmail('client@example.com')
                        ->setDate($date)
                        ->setVehicle($vehicle)
                        ->setSlot($slot)
                        ->assignSeat(1));
                }
            }
        }
        $em->flush();
    }
}
