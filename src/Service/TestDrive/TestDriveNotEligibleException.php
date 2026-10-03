<?php

declare(strict_types=1);

namespace App\Service\TestDrive;

/**
 * The reservation cannot be marked as driven (cancelled, or scheduled for a later day).
 */
final class TestDriveNotEligibleException extends \RuntimeException
{
}
