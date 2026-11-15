<?php

namespace App\Support;

/**
 * Mail configuration that would make emails vanish silently. With MAIL_MAILER
 * "log" or "array", Laravel reports success but nothing leaves the server.
 */
final class MailHealth
{
    private const NON_DELIVERING = ['log', 'array'];

    public static function mailer(): string
    {
        return (string) config('mail.default');
    }

    /** True when the configured mailer does not deliver email. */
    public static function nonDelivering(): bool
    {
        return in_array(self::mailer(), self::NON_DELIVERING, true);
    }

    /** A warning to show in production, or null. */
    public static function problem(): ?string
    {
        if (! app()->isProduction() || ! self::nonDelivering()) {
            return null;
        }

        return 'MAIL_MAILER is "'.self::mailer().'": emails (supplier POs, invitations, reports) are not delivered. Set MAIL_MAILER and the SMTP settings in .env, then clear the config cache.';
    }
}
