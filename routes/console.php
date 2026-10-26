<?php

use App\Models\SyncAttempt;
use Illuminate\Support\Facades\Schedule;

// All schedules run in the business timezone; the app clock itself is UTC.
$tz = config('bwms.business_timezone');

// NFR-9: the balance projection must equal the ledger.
Schedule::command('stock:verify')->dailyAt('02:15')->timezone($tz)->withoutOverlapping();

// NFR-9: the ledger must stay append-only at the database level.
Schedule::command('bwms:check-triggers')->dailyAt('02:10')->timezone($tz);

// Ordermentum (B3.2). Each command is a no-op while its switch is off.
Schedule::command('channels:poll-ordermentum')->everyTenMinutes()->withoutOverlapping();
Schedule::command('channels:ordermentum reconcile')->dailyAt('03:00')->timezone($tz)->withoutOverlapping();

// NFR-12: sync_attempts are kept 90 days.
Schedule::command('model:prune', ['--model' => [SyncAttempt::class]])->dailyAt('03:30')->timezone($tz);
