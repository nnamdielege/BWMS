<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page for every signed-in user. Widgets are filled in by later steps;
 * each widget checks its own permission before querying.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard');
    }
}
