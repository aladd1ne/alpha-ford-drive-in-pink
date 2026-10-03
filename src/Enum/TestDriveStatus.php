<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Whether the test drive itself took place, as confirmed by an Alpha Ford commercial.
 * Kept apart from ReservationStatus (the booking) and from FundContribution (the money).
 */
enum TestDriveStatus: string implements TranslatableInterface
{
    case ToValidate = 'to_validate';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::ToValidate => 'À valider',
            self::Completed => 'Effectué',
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
