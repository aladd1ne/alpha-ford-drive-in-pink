<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The three Drive in Pink booking journeys. The backing value is also the URL slug.
 */
enum Experience: string implements TranslatableInterface
{
    case EverestRanger = 'everest-ranger';
    case Territory = 'territory';
    case October = 'octobre';

    public function label(): string
    {
        return match ($this) {
            self::EverestRanger => 'Everest & Ranger Experience',
            self::Territory => 'Territory Experience',
            self::October => 'Autres jours d’octobre',
        };
    }

    /**
     * Lets EasyAdmin / forms display the French label instead of the case name.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->label(), [], null, $locale);
    }

    /**
     * Event days with fixed time slots; "October" requests are only a callback request.
     */
    public function hasSlots(): bool
    {
        return self::October !== $this;
    }

    /**
     * @return list<Vehicle>
     */
    public function vehicles(): array
    {
        return match ($this) {
            self::EverestRanger => [
                Vehicle::RangerRaptor,
                Vehicle::RangerWildtrak,
                Vehicle::RangerXltBva,
                Vehicle::EverestLimited,
                Vehicle::EverestXlt,
            ],
            self::Territory => [Vehicle::Territory],
            self::October => [
                Vehicle::RangerRaptor,
                Vehicle::RangerWildtrak,
                Vehicle::RangerXltBva,
                Vehicle::Everest,
                Vehicle::Territory,
                Vehicle::Advice,
            ],
        };
    }

    public function hasVehicleChoice(): bool
    {
        return \count($this->vehicles()) > 1;
    }
}
