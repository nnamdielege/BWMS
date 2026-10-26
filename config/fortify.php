<?php

use Laravel\Fortify\Features;

/*
 * Fortify is used ONLY for TOTP two-factor authentication (D-8). Its routes
 * are disabled (Fortify::ignoreRoutes in FortifyServiceProvider) and the 2FA
 * endpoints are registered explicitly in routes/auth.php and
 * routes/settings.php. Login, password reset and invitations stay in our
 * own controllers. There is no registration.
 */
return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/dashboard',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],
    'views' => false,
    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
