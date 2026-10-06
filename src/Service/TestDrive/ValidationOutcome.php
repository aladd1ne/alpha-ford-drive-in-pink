<?php

declare(strict_types=1);

namespace App\Service\TestDrive;

enum ValidationOutcome
{
    /** The test drive is now completed and its contribution was added to the fund. */
    case Validated;

    /** It was validated before: nothing was changed or added. */
    case AlreadyValidated;
}
