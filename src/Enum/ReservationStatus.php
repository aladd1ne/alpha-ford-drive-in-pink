<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lifecycle of a reservation. A booking starts "pending" and becomes "confirmed" when
 * the reservations team confirms it or its test drive is cashed in
 * (Reservation::markTestDriveCompleted()). Archiving hides it from the active lists and
 * releases its seat. Cancellation is not handled yet (cancelling must also release the seat).
 */
enum ReservationStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de confirmation',
            self::Confirmed => 'Confirmée',
            self::Cancelled => 'Annulée',
            self::Archived => 'Archivée',
        };
    }

    /**
     * Still in the active lists: neither cancelled nor archived.
     */
    public function isActive(): bool
    {
        return self::Cancelled !== $this && self::Archived !== $this;
    }

    /**
     * @return list<self>
     */
    public static function inactive(): array
    {
        return [self::Cancelled, self::Archived];
    }

    /**
     * Lets EasyAdmin / forms display the French label instead of the case name.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->label(), [], null, $locale);
    }
}
