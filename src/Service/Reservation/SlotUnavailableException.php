<?php

declare(strict_types=1);

namespace App\Service\Reservation;

final class SlotUnavailableException extends \RuntimeException
{
    public function __construct(string $message = 'Ce créneau vient d’être réservé. Merci d’en choisir un autre.')
    {
        parent::__construct($message);
    }
}
