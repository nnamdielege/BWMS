<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/settings/Company', [
            'settings' => collect(Setting::COMPANY_KEYS)->mapWithKeys(fn ($k) => [$k => Setting::get($k, '')]),
            'logo' => ['url' => Branding::logoUrl(), 'uploaded' => Branding::hasUploadedLogo()],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = collect(Setting::COMPANY_KEYS)->mapWithKeys(fn ($k) => [str_replace('.', '_', $k) => ['nullable', 'string', 'max:2000']])->all();
        $rules['company_name'] = ['required', 'string', 'max:255'];
        $rules['company_email'] = ['nullable', 'email', 'max:255'];
        $data = $request->validate($rules);

        Setting::put(collect(Setting::COMPANY_KEYS)->mapWithKeys(fn ($k) => [$k => $data[str_replace('.', '_', $k)] ?? ''])->all());

        return back()->with('success', 'Company details saved.');
    }

    public function storeLogo(Request $request): RedirectResponse
    {
        // PNG/JPEG only: SVG could carry script on the public logo route, and mPDF renders both reliably.
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000']]);
        Branding::store($request->file('logo'));

        return back()->with('success', 'Logo saved.');
    }

    public function destroyLogo(): RedirectResponse
    {
        Branding::remove();

        return back()->with('success', 'Logo removed; the bundled Local Selection logo is used.');
    }
}
