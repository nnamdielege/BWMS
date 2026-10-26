<?php

namespace App\Console\Commands;

use App\Authorization\Permissions;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Bootstraps the first administrator (or recovers access) without any default
 * password: prints a one-time invitation link to the console.
 */
class InviteAdmin extends Command
{
    protected $signature = 'bwms:invite-admin {email} {name}';

    protected $description = 'Create a one-time admin invitation link';

    public function handle(): int
    {
        if (User::where('email', strtolower($this->argument('email')))->exists()) {
            $this->error('A user with that email already exists.');

            return self::FAILURE;
        }

        [$invitation, $token] = UserInvitation::issue(
            $this->argument('email'), $this->argument('name'), Permissions::ROLE_ADMIN, null,
        );
        Audit::record('invitation_sent', $invitation, new: ['role' => Permissions::ROLE_ADMIN], meta: ['via' => 'console']);

        $this->info('Admin invitation created. Link (valid '.UserInvitation::TTL_DAYS.' days, single use):');
        $this->line(route('invitations.show', $token));

        return self::SUCCESS;
    }
}
