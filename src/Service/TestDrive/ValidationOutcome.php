<?php

declare(strict_types=1);

namespace App\Service\TestDrive;

enum ValidationOutcome
{
    /** The test drive is now completed and 30 DT were added to the fund. */
    case Validated;

    /** It was validated before: nothing was changed or added. */
    case AlreadyValidated;
}
