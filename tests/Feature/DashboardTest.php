<?php

namespace Tests\Feature;

use App\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
