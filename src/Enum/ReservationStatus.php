<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lifecycle of a reservation. Only "pending" is set for now; the back-office will
 * handle confirmation / cancellation (cancelling must also release the seat).
 */
enum ReservationStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de confirmation',
            self::Confirmed => 'Confirmée',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * Lets EasyAdmin / forms display the French label instead of the case name.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->label(), [], null, $locale);
    }
}
