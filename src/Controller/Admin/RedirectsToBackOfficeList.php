<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;

/**
 * Back to the list a POST action was sent from (the "_return" field), or a fallback.
 */
trait RedirectsToBackOfficeList
{
    /**
     * Only back-office paths are accepted, so the field cannot be used as an open redirect.
     */
    private function returnUrl(Request $request, string $fallback): string
    {
        $return = (string) $request->request->get('_return');
        if (str_starts_with($return, '/admin/') && !str_contains($return, '\\')) {
            return $return;
        }

        return $fallback;
    }
}
