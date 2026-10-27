<?php

namespace Tests\Feature;

use App\Authorization\Permissions;
use App\Mail\UserInvitationMail;
use App\Models\Setting;
use App\Models\UserInvitation;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedStockReferenceData();
    }

    private function binLabelsPdf(): string
    {
        return $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN))
            ->get(route('locations.labels', ['site' => $this->site()->id]))->assertOk()->getContent();
    }

    public function test_app_name_and_totp_issuer_are_local_selection()
    {
        $this->assertSame('Local Selection WMS', config('app.name'));

        $user = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();

        $this->assertStringContainsString('issuer=Local%20Selection%20WMS', $user->twoFactorQrCodeUrl());
        $this->assertStringNotContainsStringIgnoringCase('laravel', $user->twoFactorQrCodeUrl());
    }

    public function test_pdfs_use_the_bundled_logo_until_one_is_uploaded_then_fall_back_when_removed()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->assertSame(public_path('images/logo.png'), Branding::logoPath());
        $this->assertStringContainsString('/Width 123', $this->binLabelsPdf());

        $this->actingAs($admin)->post(route('admin.settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)])
            ->assertSessionHasNoErrors();
        $stored = Setting::get(Branding::SETTING);
        Storage::disk('local')->assertExists($stored);
        $this->assertStringContainsString('/Width 300', $this->binLabelsPdf());

        $this->actingAs($admin)->delete(route('admin.settings.logo.destroy'))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($stored);
        $this->assertSame(public_path('images/logo.png'), Branding::logoPath());
    }

    public function test_only_settings_managers_upload_and_only_png_or_jpeg()
    {
        $logo = UploadedFile::fake()->image('logo.png', 300, 300);
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))
            ->post(route('admin.settings.logo.store'), ['logo' => $logo])->assertForbidden();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN))
            ->post(route('admin.settings.logo.store'), ['logo' => $svg])->assertSessionHasErrors('logo');
        $this->assertNull(Setting::get(Branding::SETTING));
    }

    public function test_logo_route_is_public_and_serves_only_the_logo()
    {
        $this->get(route('branding.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/branding/logo?path=../../.env')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_emails_carry_the_logo_and_no_starter_kit_branding()
    {
        [$invitation, $token] = UserInvitation::issue('new@example.com', 'New', 'purchasing', null);
        $html = (new UserInvitationMail($invitation, $token))->render();

        $this->assertStringContainsString(route('branding.logo').'?v=', $html);
        $this->assertStringContainsString('Local Selection WMS', $html);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $html);
    }

    public function test_pdf_templates_reference_the_logo_by_local_path_only()
    {
        foreach (['purchase-order', 'packing-slip', 'bin-labels'] as $tpl) {
            $template = File::get(resource_path("views/pdf/{$tpl}.blade.php"));
            $this->assertStringContainsString('src="{{ $logoPath }}"', $template, "{$tpl} shows the company logo");
            $this->assertDoesNotMatchRegularExpression('#(https?:)?//|@import|url\(#i', $template, "{$tpl} must stay offline (NFR-6)");
        }
    }
}
