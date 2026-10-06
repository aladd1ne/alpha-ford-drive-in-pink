<?php

declare(strict_types=1);

namespace App\Service\Reservation;

/**
 * A back-office change refused by ReservationManager; the message is shown as is.
 */
final class ReservationChangeException extends \RuntimeException
{
    /**
     * @param string|null $field form field the error belongs to, or null for the whole form
     */
    public function __construct(string $message, public readonly ?string $field = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
