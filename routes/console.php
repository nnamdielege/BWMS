<?php

use Illuminate\Support\Facades\Schedule;

// All schedules run in the business timezone; the app clock itself is UTC.
$tz = config('bwms.business_timezone');

// NFR-9: the balance projection must equal the ledger.
Schedule::command('stock:verify')->dailyAt('02:15')->timezone($tz)->withoutOverlapping();
