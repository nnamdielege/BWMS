<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/profile')->assertOk();
    }

    public function test_name_can_be_updated_but_email_cannot()
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'Test User', 'email' => 'test@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();
        $this->assertSame('Test User', $user->name);
        $this->assertSame($originalEmail, $user->email);
    }

    public function test_users_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertStatus(405);
        $this->assertNotNull($user->fresh());
    }
}
