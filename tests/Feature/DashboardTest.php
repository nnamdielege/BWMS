<?php

namespace Tests\Feature;

use App\Authorization\Permissions;
use App\Models\SyncAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_deactivated_user_is_logged_out_on_next_request()
    {
        $user = $this->userWithRole(Permissions::ROLE_READ_ONLY);
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_sync_failures_tile_counts_the_last_24_hours_for_sync_viewers_only()
    {
        $row = ['channel' => 'ordermentum', 'operation' => 'push', 'success' => false];
        SyncAttempt::create($row);
        SyncAttempt::create(['channel' => 'portal'] + $row);
        SyncAttempt::create(['success' => true] + $row);
        SyncAttempt::create($row)->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('widgets.sync_failures_24h', 2));
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('widgets.sync_failures_24h'));
    }
}
