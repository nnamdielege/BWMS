<?php

namespace App\Services\Reports\Insights;

use App\Models\Location;
use App\Models\Product;
use App\Models\Range;
use App\Models\Supplier;
use App\Services\Stock\StockQuery;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The filters every stock insight report takes: dates (Brisbane), site, range,
 * supplier and SKU. Range, supplier and SKU narrow the products; supplier is the
 * product's usual supplier (Stock received uses the PO's supplier instead).
 */
final class InsightFilters
{
    public const MAX_DAYS = 731;

    /** @var ?list<int> */
    private ?array $binIds = null;

    /** @var ?list<int> */
    private ?array $productIds = null;

    private bool $resolved = false;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $siteId = null,
        public readonly ?int $rangeId = null,
        public readonly ?int $supplierId = null,
        public readonly ?int $productId = null,
    ) {}

    /** The last $days days to today unless the request gives dates; at most two years. */
    public static function fromRequest(Request $request, int $days = 30): self
    {
        $d = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'site_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', Location::TYPE_SITE)],
            'range_id' => ['nullable', 'integer', 'exists:ranges,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);
        $to = $d['to'] ?? BusinessDate::today();
        $from = $d['from'] ?? Carbon::parse($to)->subDays($days - 1)->toDateString();
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_DAYS) {
            $from = Carbon::parse($to)->subDays(self::MAX_DAYS)->toDateString();
        }

        return new self($from, $to, self::int($d, 'site_id'), self::int($d, 'range_id'), self::int($d, 'supplier_id'), self::int($d, 'product_id'));
    }

    private static function int(array $d, string $key): ?int
    {
        return isset($d[$key]) ? (int) $d[$key] : null;
    }

    /** @return array{0: Carbon, 1: Carbon} the dates as UTC instants, start and end of day in Brisbane */
    public function window(): array
    {
        $tz = config('bwms.business_timezone');

        return [Carbon::parse($this->from, $tz)->startOfDay()->utc(), Carbon::parse($this->to, $tz)->endOfDay()->utc()];
    }

    /** Bins of the chosen site; null = every site. */
    public function binIds(): ?array
    {
        $this->resolve();

        return $this->binIds;
    }

    /** Products matching range, supplier and SKU; null = no product filter. */
    public function productIds(): ?array
    {
        $this->resolve();

        return $this->productIds;
    }

    public function hasProductFilter(): bool
    {
        return $this->rangeId !== null || $this->supplierId !== null || $this->productId !== null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        if ($this->siteId !== null) {
            $this->binIds = app(StockQuery::class)->binIdsUnder(Location::findOrFail($this->siteId));
        }
        if ($this->hasProductFilter()) {
            $this->productIds = Product::withTrashed()
                ->when($this->rangeId, fn ($q, $id) => $q->where('range_id', $id))
                ->when($this->supplierId, fn ($q, $id) => $q->where('supplier_id', $id))
                ->when($this->productId, fn ($q, $id) => $q->whereKey($id))
                ->pluck('id')->all();
        }
    }

    /** Narrow a query by product and bin columns (either may be null to skip). */
    public function apply($query, ?string $productColumn, ?string $binColumn = null)
    {
        if ($productColumn !== null && $this->productIds() !== null) {
            $query->whereIn($productColumn, $this->productIds());
        }
        if ($binColumn !== null && $this->binIds() !== null) {
            $query->whereIn($binColumn, $this->binIds());
        }

        return $query;
    }

    /** @return array{from: string, to: string, site_id: ?int, range_id: ?int, supplier_id: ?int, product_id: ?int} */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'site_id' => $this->siteId,
            'range_id' => $this->rangeId, 'supplier_id' => $this->supplierId, 'product_id' => $this->productId,
        ];
    }

    /** One line for an export's header: "1 Nov 2026 to 30 Nov 2026 · Site MAIN · Range Dairy". */
    public function describe(): string
    {
        $parts = [Carbon::parse($this->from)->format('j M Y').' to '.Carbon::parse($this->to)->format('j M Y')];
        if ($this->siteId) {
            $parts[] = 'Site '.Location::find($this->siteId)?->name;
        }
        if ($this->rangeId) {
            $parts[] = 'Range '.Range::find($this->rangeId)?->name;
        }
        if ($this->supplierId) {
            $parts[] = 'Supplier '.Supplier::find($this->supplierId)?->name;
        }
        if ($this->productId) {
            $p = Product::withTrashed()->find($this->productId);
            $parts[] = "SKU {$p?->sku} {$p?->name}";
        }

        return implode(' · ', $parts);
    }

    /** What the filter bar offers. */
    public static function options(?int $productId = null): array
    {
        $product = $productId ? Product::withTrashed()->find($productId, ['id', 'sku', 'name']) : null;

        return [
            'sites' => Location::where('type', Location::TYPE_SITE)->orderBy('name')->get(['id', 'name'])->toArray(),
            'ranges' => Range::orderBy('name')->get(['id', 'name'])->toArray(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name'])->toArray(),
            'product' => $product ? ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name] : null,
        ];
    }
}
