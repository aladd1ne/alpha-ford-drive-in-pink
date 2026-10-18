<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where an amount added to the cagnotte outside any test drive comes from
 * (FundContribution::manual()).
 */
enum ContributionSource: string implements TranslatableInterface
{
    case Donation = 'donation';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Donation => 'Don',
            self::Product => 'Achat d’un produit',
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
