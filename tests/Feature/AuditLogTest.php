<?php

namespace Tests\Feature;

use App\Authorization\Permissions;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_changes_are_recorded_without_sensitive_fields()
    {
        $actor = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->actingAs($actor);

        $user = User::factory()->create(['name' => 'Before']);
        $user->update(['name' => 'After', 'password' => 'changed-secret']);

        $created = AuditLog::where('event', 'created')->where('auditable_id', $user->id)->firstOrFail();
        $this->assertArrayNotHasKey('password', $created->new_values);

        $updated = AuditLog::where('event', 'updated')->where('auditable_id', $user->id)->firstOrFail();
        $this->assertSame(['name' => 'Before'], $updated->old_values);
        $this->assertSame(['name' => 'After'], $updated->new_values);
        $this->assertSame($actor->id, $updated->user_id);
    }

    public function test_audit_entries_are_immutable()
    {
        $log = AuditLog::create(['event' => 'test']);

        $this->expectException(LogicException::class);
        $log->update(['event' => 'tampered']);
    }

    public function test_audit_entries_cannot_be_deleted()
    {
        $log = AuditLog::create(['event' => 'test']);

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_logins_and_failures_are_audited()
    {
        $user = $this->userWithRole(Permissions::ROLE_READ_ONLY);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'login_failed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'login', 'user_id' => $user->id]);
    }

    public function test_audit_page_requires_permission()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN))->get(route('admin.audit.index'))->assertOk();
    }
}
