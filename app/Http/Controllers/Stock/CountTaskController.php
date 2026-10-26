<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\CountTask;
use App\Services\Stock\CountTaskService;
use App\Services\Stock\Exceptions\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CountTaskController extends Controller
{
    public function dismiss(Request $request, CountTask $countTask, CountTaskService $service): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        try {
            $service->dismiss($countTask, $request->user(), $data['note']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['count_task' => $e->getMessage()]);
        }

        return back()->with('success', 'Count task dismissed.');
    }
}
