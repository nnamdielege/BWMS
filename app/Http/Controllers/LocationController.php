<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('locations/Index', [
            'locations' => Location::orderBy('parent_id')->orderBy('sort_order')->orderBy('code')->get(),
            'canManage' => $request->user()->can('create', Location::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Location::create($data);

        return back()->with('success', "Location {$data['code']} created.");
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $data = $this->validated($request, $location);

        if ($data['type'] !== $location->type && $location->children()->exists()) {
            throw ValidationException::withMessages(['type' => 'A location with children cannot change type.']);
        }

        $location->update($data);

        return back()->with('success', "Location {$location->code} saved.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Location $location = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'type' => ['required', Rule::in(Location::TYPES)],
            'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9._\-]*$/',
                Rule::unique('locations')->where('parent_id', $request->input('parent_id'))->ignore($location?->id)],
            'name' => ['required', 'string', 'max:255'],
            'is_sellable' => ['boolean'],
            'is_quarantine' => ['boolean'],
            'is_receiving' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $parent = isset($data['parent_id']) ? Location::find($data['parent_id']) : null;

        if ($data['type'] === Location::TYPE_SITE && $parent) {
            throw ValidationException::withMessages(['parent_id' => 'A site cannot have a parent.']);
        }
        if ($data['type'] !== Location::TYPE_SITE && (! $parent || $parent->isBin())) {
            throw ValidationException::withMessages(['parent_id' => 'Zones and bins must sit under a site or zone.']);
        }
        if ($location && $parent && $this->isDescendant($parent, $location)) {
            throw ValidationException::withMessages(['parent_id' => 'A location cannot be moved under itself.']);
        }
        if (! empty($data['is_quarantine'])) {
            $data['is_sellable'] = false; // quarantined stock is never available to sell
        }
        $data['sort_order'] ??= 0;

        return $data;
    }

    private function isDescendant(Location $candidate, Location $ancestor): bool
    {
        $node = $candidate;
        $guard = 0;
        while ($node && $guard++ < 20) {
            if ($node->is($ancestor)) {
                return true;
            }
            $node = $node->parent;
        }

        return false;
    }
}
