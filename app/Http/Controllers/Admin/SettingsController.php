<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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
}
