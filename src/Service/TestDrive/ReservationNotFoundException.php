<?php

declare(strict_types=1);

namespace App\Service\TestDrive;

final class ReservationNotFoundException extends \RuntimeException
{
    public function __construct(int $reservationId)
    {
        parent::__construct(sprintf('Réservation #%d introuvable.', $reservationId));
    }
}
