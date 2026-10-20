<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Donor or buyer who did not register on the site, typed by hand in the back-office: the
 * details are kept on the contribution itself (see FundContribution::donation()).
 */
final class UnregisteredDonor
{
    public readonly string $fullName;
    public readonly string $phone;
    public readonly ?string $email;

    public function __construct(?string $fullName, ?string $phone, ?string $email = null)
    {
        $this->fullName = trim((string) $fullName);
        $this->phone = trim((string) $phone);
        $email = mb_strtolower(trim((string) $email));
        $this->email = '' === $email ? null : $email;
    }
}
