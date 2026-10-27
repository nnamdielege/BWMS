<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The company logo: uploaded in Company settings (PNG/JPEG on the private local
 * disk), falling back to the bundled Local Selection logo. PDFs read the file
 * from disk (no network, NFR-6); emails link to the public branding.logo route.
 */
final class Branding
{
    public const SETTING = 'company.logo';

    private const DIR = 'branding';

    public static function bundledPath(): string
    {
        return public_path('images/logo.png');
    }

    /** Absolute path of the logo to use: the uploaded one if present, else the bundled file. */
    public static function logoPath(): string
    {
        $stored = Setting::get(self::SETTING);

        if (filled($stored) && Storage::disk('local')->exists($stored)) {
            return Storage::disk('local')->path($stored);
        }

        return self::bundledPath();
    }

    public static function hasUploadedLogo(): bool
    {
        return self::logoPath() !== self::bundledPath();
    }

    /** Absolute URL for emails; the version changes with the file so mail clients don't show a stale copy. */
    public static function logoUrl(): string
    {
        $path = self::logoPath();

        return route('branding.logo', ['v' => substr(md5($path.'|'.@filemtime($path)), 0, 8)]);
    }

    public static function store(UploadedFile $file): void
    {
        $previous = Setting::get(self::SETTING);
        $stored = $file->storeAs(self::DIR, 'logo-'.now()->format('YmdHis').'.'.$file->extension(), 'local');

        Setting::put([self::SETTING => $stored]);

        if (filled($previous) && $previous !== $stored) {
            Storage::disk('local')->delete($previous);
        }
    }

    public static function remove(): void
    {
        $previous = Setting::get(self::SETTING);
        Setting::put([self::SETTING => null]);

        if (filled($previous)) {
            Storage::disk('local')->delete($previous);
        }
    }
}
