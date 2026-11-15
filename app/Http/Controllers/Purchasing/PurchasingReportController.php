<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\CashOutReport;
use App\Services\Purchasing\SlowMoversReport;
use Inertia\Inertia;
use Inertia\Response;

/** Purchasing → Cash out and Purchasing → Slow movers. The figures come from the services. */
class PurchasingReportController extends Controller
{
    public function cashOut(CashOutReport $report): Response
    {
        return Inertia::render('purchasing/CashOut', ['report' => $report->build()]);
    }

    public function slowMovers(SlowMoversReport $report): Response
    {
        return Inertia::render('purchasing/SlowMovers', ['report' => $report->build(), 'threshold' => SlowMoversReport::MAX_VELOCITY]);
    }
}
