<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum Vehicle: string implements TranslatableInterface
{
    case RangerRaptor = 'ranger_raptor';
    case RangerWildtrak = 'ranger_wildtrak';
    case RangerXltBva = 'ranger_xlt_bva';
    case EverestLimited = 'everest_limited';
    case EverestXlt = 'everest_xlt';
    case Everest = 'everest';
    case Territory = 'territory';
    case Advice = 'advice';

    public function label(): string
    {
        return match ($this) {
            self::RangerRaptor => 'Ranger Raptor',
            self::RangerWildtrak => 'Ranger Wildtrak',
            self::RangerXltBva => 'Ranger XLT BVA',
            self::EverestLimited => 'Everest Limited',
            self::EverestXlt => 'Everest XLT',
            self::Everest => 'Everest',
            self::Territory => 'Territory',
            self::Advice => 'Je souhaite être conseillé(e)',
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
