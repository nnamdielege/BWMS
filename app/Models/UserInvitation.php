<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserInvitation extends Model
{
    use Auditable;

    public const TTL_DAYS = 7;

    protected $fillable = ['email', 'name', 'role', 'token_hash', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    /** @var list<string> */
    protected array $auditExclude = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create an invitation and return it with the plain token (shown once, emailed).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $email, string $name, string $role, ?User $by): array
    {
        $token = Str::random(64);

        $invitation = self::create([
            'email' => Str::lower($email),
            'name' => $name,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $by?->id,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return [$invitation, $token];
    }

    /** Rotate the token (used by "resend"); returns the new plain token. */
    public function rotateToken(): string
    {
        $token = Str::random(64);
        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ])->save();

        return $token;
    }

    public static function findPendingByToken(string $token): ?self
    {
        return self::pending()->where('token_hash', hash('sha256', $token))->first();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
