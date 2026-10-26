<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Models\Setting;
use App\Models\Supplier;
use Database\Seeders\CompanySettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchasing_can_create_and_edit_suppliers()
    {
        $buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);

        $this->actingAs($buyer)->post(route('suppliers.store'), [
            'code' => 'mtr', 'name' => 'Mountain Tea Roasters', 'email' => 'orders@mtr.test', 'cc_emails' => 'a@mtr.test, b@mtr.test', 'lead_time_days' => 3,
        ])->assertSessionHasNoErrors();

        $supplier = Supplier::where('code', 'MTR')->sole();
        $this->assertSame(['a@mtr.test', 'b@mtr.test'], $supplier->ccList());

        $this->actingAs($buyer)->put(route('suppliers.update', $supplier), ['code' => 'MTR', 'name' => 'MTR Pty Ltd', 'cc_emails' => 'not-an-email'])
            ->assertSessionHasErrors('cc_emails');
    }

    public function test_staff_can_view_but_not_manage_suppliers()
    {
        $staff = $this->userWithRole(Permissions::ROLE_READ_ONLY);

        $this->actingAs($staff)->get(route('suppliers.index'))->assertOk();
        $this->actingAs($staff)->post(route('suppliers.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }

    public function test_company_settings_seeded_once_and_editable_by_admin_only()
    {
        $this->seed(CompanySettingsSeeder::class);
        Setting::put(['company.name' => 'Local Selection Pty Ltd']);
        $this->seed(CompanySettingsSeeder::class); // must not overwrite

        $this->assertSame('Local Selection Pty Ltd', Setting::get('company.name'));

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN))
            ->put(route('admin.settings.update'), ['company_name' => 'Local Selection', 'company_abn' => '12 345 678 901'])
            ->assertSessionHasNoErrors();
        $this->assertSame('12 345 678 901', Setting::get('company.abn'));
    }
}
