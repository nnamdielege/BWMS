<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\Branding;
use App\Support\MailHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/settings/Company', [
            'settings' => collect(Setting::COMPANY_KEYS)->mapWithKeys(fn ($k) => [$k => Setting::get($k, '')]),
            'requireBinScan' => Setting::requiresBinScan(),
            'reorderEmailTo' => implode(', ', Setting::reorderEmailTo()),
            'reorderEmailOn' => Setting::reorder()['daily_email'],
            'logo' => ['url' => Branding::logoUrl(), 'uploaded' => Branding::hasUploadedLogo()],
            'mail' => ['mailer' => MailHealth::mailer(), 'problem' => MailHealth::problem(), 'from' => config('mail.from.address')],
            'testEmail' => session('test_email'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = collect(Setting::COMPANY_KEYS)->mapWithKeys(fn ($k) => [str_replace('.', '_', $k) => ['nullable', 'string', 'max:2000']])->all();
        $rules['company_name'] = ['required', 'string', 'max:255'];
        $rules['company_email'] = ['nullable', 'email', 'max:255'];
        $rules['require_bin_scan'] = ['sometimes', 'boolean'];
        $rules['reorder_email_to'] = ['sometimes', 'nullable', 'string', 'max:1000', function (string $attr, mixed $value, \Closure $fail) {
            foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $fail("\"{$address}\" is not a valid email address.");
                }
            }
        }];
        $data = $request->validate($rules);

        Setting::put(collect(Setting::COMPANY_KEYS)->mapWithKeys(fn ($k) => [$k => $data[str_replace('.', '_', $k)] ?? ''])->all());
        if (array_key_exists('require_bin_scan', $data)) {
            Setting::put([Setting::REQUIRE_BIN_SCAN => $data['require_bin_scan'] ? '1' : '0']);
        }
        if (array_key_exists('reorder_email_to', $data)) {
            $to = implode(', ', array_values(array_unique(array_map('strtolower', array_filter(array_map('trim', explode(',', (string) $data['reorder_email_to'])))))));
            $before = implode(', ', Setting::reorderEmailTo());
            Setting::put([Setting::REORDER_EMAIL_TO => $to]);
            if ($to !== $before) {
                Audit::record('reorder_email_recipients_changed', null, old: ['to' => $before], new: ['to' => $to]);
            }
        }

        return back()->with('success', 'Company details saved.');
    }

    /**
     * Sends a test email to the logged-in user right now (not queued), so the result, or the
     * mail server's exact error, shows on the page. A non-delivering mailer counts as a failure.
     */
    public function testEmail(Request $request): RedirectResponse
    {
        $to = $request->user()->email;
        $mailer = MailHealth::mailer();
        if (MailHealth::nonDelivering()) {
            return $this->testResult(false, $to, "Not sent: MAIL_MAILER is \"{$mailer}\", which does not deliver email. Set MAIL_MAILER and the SMTP settings in .env, then clear the config cache.");
        }

        try {
            Mail::to($to)->send(new TestMail($mailer, now(config('bwms.business_timezone'))->format('j M Y H:i')));
        } catch (Throwable $e) {
            report($e);

            return $this->testResult(false, $to, trim($e->getMessage()) ?: class_basename($e));
        }

        return $this->testResult(true, $to, "Test email sent to {$to} via \"{$mailer}\". Check that inbox (and spam) to confirm it arrived.");
    }

    private function testResult(bool $ok, string $to, string $message): RedirectResponse
    {
        return back()->with('test_email', ['ok' => $ok, 'to' => $to, 'message' => mb_substr($message, 0, 2000)]);
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
