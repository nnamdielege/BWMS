<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public logo for email clients, which can't log in. Serves only the company
 * logo (an image chosen to be public), never any other stored file.
 */
class BrandingController extends Controller
{
    public function logo(): BinaryFileResponse
    {
        return response()->file(Branding::logoPath(), [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
