<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\AllocationAccount;
use App\Models\AllocationReceipt;
use App\Models\AllocationRun;
use App\Services\Documents\CsvWriter;
use App\Services\Documents\SpreadsheetReader;
use App\Services\Finance\AllocationMath;
use App\Services\Finance\IncomeAllocator;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Finance → Income allocation (admins only). Advice only: nothing here moves money.
 * The calculation is a preview until "Mark transfers done" records it.
 */
class IncomeAllocationController extends Controller
{
    public function __construct(private IncomeAllocator $allocator) {}

    public function index(Request $request): Response
    {
        $period = $this->period($request);

        return Inertia::render('finance/IncomeAllocation', [
            'period' => $period,
            'nextDate' => $this->allocator->nextAllocationDate(),
            'doneForDate' => AllocationRun::whereDate('allocation_date', $period['date'])->exists(),
            'calc' => $this->allocator->calculate($period['date'], $period['from'], $period['to'], $this->opening($request)),
            'receipts' => AllocationReceipt::waiting()->with('order:id,number')->orderBy('received_on')->orderBy('id')->get()
                ->map(fn (AllocationReceipt $r) => ['id' => $r->id, 'received_on' => $r->received_on->toDateString(), 'description' => $r->description,
                    'amount' => (float) $r->amount, 'source' => $r->source, 'order' => $r->order?->number]),
            'accounts' => AllocationAccount::ordered()->map(fn (AllocationAccount $a) => ['role' => $a->role, 'name' => $a->name, 'xero_code' => $a->xero_code,
                'is_split' => $a->isSplit(), 'takes_share' => $a->takesShare(), 'is_active' => $a->is_active]),
            'history' => $this->allocator->history(),
        ]);
    }

    public function storeReceipt(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'received_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'order_number' => ['nullable', 'string', 'max:64'],
        ]);
        $r = $this->allocator->addReceipt($request->user(), $data);

        return back()->with('success', 'Receipt of $'.number_format((float) $r->amount, 2).' added.');
    }

    public function import(Request $request, SpreadsheetReader $reader): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        $file = $request->file('file');
        $result = $this->allocator->import($request->user(), $reader->rows($file->getRealPath(), 'csv', 20000), $file->getClientOriginalName());

        $notes = array_filter([
            $result['duplicates'] ? "{$result['duplicates']} already imported" : null,
            $result['debits'] ? "{$result['debits']} debits skipped" : null,
            $result['invalid'] ? "{$result['invalid']} rows not read" : null,
        ]);

        return back()->with($result['added'] ? 'success' : 'error',
            "Imported {$result['added']} ".($result['added'] === 1 ? 'credit' : 'credits').' ($'.number_format($result['total'], 2).')'.($notes ? ': '.implode(', ', $notes) : '').'.');
    }

    public function destroyReceipt(AllocationReceipt $receipt): RedirectResponse
    {
        if ($receipt->allocation_run_id !== null) {
            throw ValidationException::withMessages(['receipt' => 'That receipt is already allocated.']);
        }
        $receipt->delete();

        return back()->with('success', 'Receipt removed.');
    }

    /** The transfers as calculated now, for internet banking. */
    public function export(Request $request): HttpResponse
    {
        $p = $this->period($request);
        $calc = $this->allocator->calculate($p['date'], $p['from'], $p['to'], $this->opening($request));

        return $this->csv($calc, "income-allocation-{$p['date']}.csv");
    }

    public function done(Request $request): RedirectResponse
    {
        $p = $this->period($request);
        $request->validate(['receipts_total' => ['required', 'numeric']]);
        $run = $this->allocator->record($request->user(), $p['date'], $p['from'], $p['to'], $this->opening($request), AllocationMath::cents($request->input('receipts_total')));

        return redirect()->route('finance.allocation.index')
            ->with('success', 'Transfers for '.$run->allocation_date->format('j M Y').' recorded: $'.number_format((float) $run->receipts_total, 2).'.');
    }

    public function runExport(AllocationRun $run): HttpResponse
    {
        return $this->csv($run->snapshot, "income-allocation-{$run->allocation_date->toDateString()}.csv");
    }

    /** @return array{date: string, from: string, to: string} */
    private function period(Request $request): array
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'opening' => ['nullable', 'array'],
            'opening.*' => ['nullable', 'numeric', 'between:-99999999,99999999'],
        ]);
        $p = $this->allocator->defaults($request->input('date'));
        $from = BusinessDate::parse($request->input('from')) ?? $p['from'];
        $to = BusinessDate::parse($request->input('to')) ?? $p['to'];
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The period must start on or before it ends.']);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92) {
            throw ValidationException::withMessages(['from' => 'The period can be at most three months.']);
        }

        return ['date' => $p['date'], 'from' => $from, 'to' => $to];
    }

    /** @return array<string, ?string> */
    private function opening(Request $request): array
    {
        return array_intersect_key((array) $request->input('opening', []), array_flip(AllocationAccount::SPLIT_ROLES));
    }

    private function csv(array $calc, string $name): HttpResponse
    {
        return response(CsvWriter::toString(IncomeAllocator::csvRows($calc)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
