<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryArea;
use App\Models\DeliveryAreaPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Settings -> Delivery areas: weekdays, next-day flag and suburb/postcode lists. */
class DeliveryAreaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/DeliveryAreas', [
            'areas' => DeliveryArea::with('places')->orderBy('sort_order')->orderBy('name')->get()->map(fn (DeliveryArea $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'weekdays' => array_map('intval', $a->weekdays ?? []),
                'next_day_eligible' => $a->next_day_eligible, 'is_active' => $a->is_active,
                // One line per place: "4019" (whole postcode) or "4019 Clontarf".
                'places' => $a->places->map(fn ($p) => trim($p->postcode.' '.$p->suburb))->implode("\n"),
            ]),
            'cutoff' => config('bwms.delivery.cutoff_time'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $area = DeliveryArea::create(collect($data)->except('places')->all() + ['sort_order' => (int) DeliveryArea::max('sort_order') + 1]);
            $this->syncPlaces($area, $data['places']);
        });

        return back()->with('success', 'Delivery area added.');
    }

    public function update(Request $request, DeliveryArea $area): RedirectResponse
    {
        $data = $this->validated($request, $area);
        DB::transaction(function () use ($area, $data) {
            $area->update(collect($data)->except('places')->all());
            $this->syncPlaces($area, $data['places']);
        });

        return back()->with('success', "{$area->name} saved.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DeliveryArea $area = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('delivery_areas')->ignore($area?->id)],
            'name' => ['required', 'string', 'max:255'],
            'weekdays' => ['array'],
            'weekdays.*' => ['integer', 'between:1,5'],
            'next_day_eligible' => ['boolean'],
            'is_active' => ['boolean'],
            'places' => ['nullable', 'string', 'max:20000'],
        ]);
        if (($data['weekdays'] ?? []) === [] && empty($data['next_day_eligible'])) {
            throw ValidationException::withMessages(['weekdays' => 'Choose at least one delivery weekday, or next-day delivery.']);
        }

        $places = [];
        foreach (preg_split('/\R/', (string) ($data['places'] ?? '')) as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! preg_match('/^(\d{4})(?:\s+(.+))?$/u', $line, $m)) {
                throw ValidationException::withMessages(['places' => 'Line '.($i + 1).': write "4019" or "4019 Clontarf".']);
            }
            $places[$m[1].'|'.mb_strtolower(trim($m[2] ?? ''))] = ['postcode' => $m[1], 'suburb' => trim($m[2] ?? '')];
        }
        $taken = DeliveryAreaPlace::where('delivery_area_id', '!=', $area?->id ?? 0)
            ->where(fn ($q) => collect($places)->each(fn ($p) => $q->orWhere(fn ($w) => $w->where('postcode', $p['postcode'])->where('suburb', $p['suburb']))))
            ->with('area:id,name')->first();
        if ($places !== [] && $taken) {
            throw ValidationException::withMessages(['places' => trim("{$taken->postcode} {$taken->suburb}")." is already in {$taken->area->name}."]);
        }
        $data['weekdays'] = array_values(array_unique(array_map('intval', $data['weekdays'] ?? [])));
        $data['places'] = array_values($places);

        return $data;
    }

    private function syncPlaces(DeliveryArea $area, array $places): void
    {
        $area->places()->delete();
        foreach ($places as $p) {
            $area->places()->create($p);
        }
    }
}
