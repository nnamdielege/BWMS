<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type Missing = ('cost' | 'sell')[];
interface StockRow {
    id: number;
    sku: string;
    name: string;
    is_active: boolean;
    pack: string;
    pack_qty: number;
    base_unit: string;
    qty_base: number;
    packs: number;
    cost_per_pack: number | null;
    sell_per_pack: number | null;
    sell_source: 'default' | 'ordermentum' | null;
    value_cost: number | null;
    value_sell: number | null;
    gm_percent: number | null;
    reorder_packs: number | null;
    last_received: string | null;
    missing: Missing;
}
interface Totals {
    value_cost: number;
    value_sell: number;
    gp: number;
    gm_percent: number | null;
    products: number;
    excluded: number;
}
interface DropRow {
    id: number;
    sku: string;
    name: string;
    range: string;
    pack: string;
    sell_per_pack: number;
    cost_per_pack: number;
    sell_source: string;
    sales: number;
    cost: number;
    gp: number;
    net: number;
    gm_percent: number | null;
    break_even: number | null;
}
interface DropSummary {
    drops: number;
    sales: number;
    cost: number;
    gp: number;
    delivery_cost: number;
    net: number;
    avg_net: number | null;
    under_minimum: number;
}
interface RealOrder {
    id: number;
    number: string;
    delivery_date: string | null;
    customer: string | null;
    area: string;
    run: string | null;
    sales: number;
    cost: number;
    gp: number;
    delivery_cost: number;
    net: number;
    under_minimum: boolean;
    cost_basis: 'dispatch' | 'current' | 'mixed';
    price_estimated: boolean;
}

const props = defineProps<{
    filters: { basis: 'on_hand' | 'available'; sellable: boolean; units: number; from: string; to: string };
    settings: { delivery_cost: number; free_delivery_min: number };
    stock: {
        groups: { range: string; rows: StockRow[]; totals: Totals }[];
        totals: Totals;
        excluded: { count: number; with_stock: number; no_cost: number; no_sell: number };
    };
    modelled: { rows: DropRow[]; missing: { id: number; sku: string; name: string; range: string; missing: Missing }[] };
    real: { orders: RealOrder[]; areas: (DropSummary & { area: string })[]; totals: DropSummary };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '/reports/margin' },
    { title: 'Stock value & margin', href: '/reports/margin' },
];

const tabs = [
    { key: 'stock', label: 'Stock value' },
    { key: 'drops', label: 'Drop profitability' },
    { key: 'real', label: 'Real drops' },
] as const;
type Tab = (typeof tabs)[number]['key'];
const initialTab = (): Tab => {
    const t = new URLSearchParams(window.location.search).get('tab');
    return tabs.some((x) => x.key === t) ? (t as Tab) : 'stock';
};
const tab = ref<Tab>(initialTab());

const filter = reactive({ ...props.filters });
const query = () => ({ ...filter, sellable: filter.sellable ? 1 : 0, tab: tab.value });
const reload = () => router.get(route('reports.margin.index'), query(), { preserveState: true, preserveScroll: true, replace: true });
const exportUrl = (view: Tab, format: 'xlsx' | 'csv') => route('reports.margin.export', { view, format, ...query() });

const settingsForm = useForm({ ...props.settings });
const saveSettings = () => settingsForm.put(route('reports.margin.settings'), { preserveScroll: true });

const money = new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD' });
const aud = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money.format(v));
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v.toFixed(1)}%`);
const qty = (v: number) => (Number.isInteger(v) ? String(v) : v.toFixed(2));
const packLabel = (r: { pack: string; pack_qty: number }) => (r.pack_qty > 1 ? `${r.pack} ×${r.pack_qty}` : r.pack);
const flag = (m: Missing) => (m.length === 2 ? 'No cost or sell price' : m[0] === 'cost' ? 'No cost' : 'No sell price');

const hideZero = ref(false);
const visible = (rows: StockRow[]) => (hideZero.value ? rows.filter((r) => r.qty_base !== 0) : rows);
const losing = computed(() => props.modelled.rows.filter((r) => r.net < 0).length);
</script>

<template>
    <Head title="Stock value & margin" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <header class="flex flex-wrap items-center gap-4">
                <h1 class="text-xl font-semibold">Stock value &amp; margin</h1>
                <nav class="flex gap-1 rounded-lg border p-1 text-sm">
                    <button
                        v-for="t in tabs"
                        :key="t.key"
                        type="button"
                        class="rounded-md px-3 py-1"
                        :class="tab === t.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="tab = t.key"
                    >
                        {{ t.label }}
                    </button>
                </nav>
                <span class="ml-auto flex gap-3 text-sm">
                    Export: <a :href="exportUrl(tab, 'xlsx')" class="underline">XLSX</a> <a :href="exportUrl(tab, 'csv')" class="underline">CSV</a>
                </span>
            </header>
            <p class="text-sm text-muted-foreground">
                All money is ex GST. Cost is the weighted average cost (COGS); sell price is the product's default sell price, or the Ordermentum
                price when it has none. Figures are per pack: the default sale pack.
            </p>

            <!-- Stock value -->
            <section v-if="tab === 'stock'" class="space-y-4">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">Total at cost</div>
                        <div class="text-2xl font-semibold tabular-nums">{{ aud(stock.totals.value_cost) }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">Total at sell</div>
                        <div class="text-2xl font-semibold tabular-nums">{{ aud(stock.totals.value_sell) }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">Potential GP</div>
                        <div class="text-2xl font-semibold tabular-nums">{{ aud(stock.totals.gp) }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">Weighted GM</div>
                        <div class="text-2xl font-semibold tabular-nums">{{ pct(stock.totals.gm_percent) }}</div>
                    </div>
                </div>
                <p v-if="stock.excluded.count" class="rounded bg-amber-100 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    {{ stock.excluded.count }} product(s) are left out of every total ({{ stock.excluded.no_cost }} with no cost,
                    {{ stock.excluded.no_sell }} with no sell price; {{ stock.excluded.with_stock }} of them hold stock). They are flagged below.
                    <a :href="route('product-costs.index')" class="underline">Fill in costs</a>
                </p>

                <form class="flex flex-wrap items-center gap-4 text-sm" @submit.prevent>
                    <label class="flex items-center gap-2">
                        Quantity
                        <select v-model="filter.basis" class="h-8 rounded-md border bg-background px-2" @change="reload">
                            <option value="on_hand">On hand</option>
                            <option value="available">Available (sellable, not reserved)</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2"
                        ><input v-model="filter.sellable" type="checkbox" @change="reload" /> Sellable products only</label
                    >
                    <label class="flex items-center gap-2"><input v-model="hideZero" type="checkbox" /> Hide zero stock</label>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr>
                                <th class="py-2">SKU</th>
                                <th>Product</th>
                                <th>Pack</th>
                                <th class="text-right">{{ filter.basis === 'available' ? 'Available' : 'On hand' }} (packs)</th>
                                <th class="text-right">Avg cost / pack</th>
                                <th class="text-right">Sell / pack</th>
                                <th class="text-right">Value at cost</th>
                                <th class="text-right">Value at sell</th>
                                <th class="text-right">GM %</th>
                                <th class="text-right">Reorder</th>
                                <th>Last received</th>
                            </tr>
                        </thead>
                        <tbody v-for="g in stock.groups" :key="g.range">
                            <tr class="bg-muted/50">
                                <td colspan="11" class="py-1.5 font-semibold">{{ g.range }}</td>
                            </tr>
                            <tr
                                v-for="r in visible(g.rows)"
                                :key="r.id"
                                class="border-b"
                                :class="{ 'bg-amber-50 dark:bg-amber-950/40': r.missing.length }"
                            >
                                <td class="py-1.5 font-mono">{{ r.sku }}</td>
                                <td>
                                    {{ r.name }}
                                    <span v-if="!r.is_active" class="ml-1 text-xs text-muted-foreground">(inactive)</span>
                                    <span v-if="r.missing.length" class="ml-1 rounded bg-amber-200 px-1 text-xs text-amber-900">{{
                                        flag(r.missing)
                                    }}</span>
                                </td>
                                <td>{{ packLabel(r) }}</td>
                                <td class="text-right tabular-nums" :class="{ 'text-red-600': r.qty_base < 0 }">{{ qty(r.packs) }}</td>
                                <td class="text-right tabular-nums">{{ aud(r.cost_per_pack) }}</td>
                                <td class="text-right tabular-nums">
                                    {{ aud(r.sell_per_pack) }}
                                    <span
                                        v-if="r.sell_source === 'ordermentum'"
                                        class="text-xs text-muted-foreground"
                                        title="Ordermentum listing price"
                                        >OM</span
                                    >
                                </td>
                                <td class="text-right tabular-nums">{{ aud(r.value_cost) }}</td>
                                <td class="text-right tabular-nums">{{ aud(r.value_sell) }}</td>
                                <td class="text-right tabular-nums" :class="{ 'text-red-600': (r.gm_percent ?? 0) < 0 }">{{ pct(r.gm_percent) }}</td>
                                <td class="text-right tabular-nums">{{ r.reorder_packs ?? '' }}</td>
                                <td>{{ r.last_received ?? '—' }}</td>
                            </tr>
                            <tr class="border-b-2 font-medium">
                                <td colspan="6" class="py-1.5 text-right">
                                    {{ g.range }} total<span v-if="g.totals.excluded" class="font-normal text-muted-foreground">
                                        ({{ g.totals.excluded }} left out)</span
                                    >
                                </td>
                                <td class="text-right tabular-nums">{{ aud(g.totals.value_cost) }}</td>
                                <td class="text-right tabular-nums">{{ aud(g.totals.value_sell) }}</td>
                                <td class="text-right tabular-nums">{{ pct(g.totals.gm_percent) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!stock.groups.length" class="py-6 text-center text-muted-foreground">No products to show.</p>
            </section>

            <!-- Delivery settings, shared by both drop views -->
            <form v-if="tab !== 'stock'" class="flex flex-wrap items-end gap-4 rounded-lg border p-4 text-sm" @submit.prevent="saveSettings">
                <label v-if="tab === 'drops'" class="space-y-1">
                    <span class="block text-muted-foreground">Packs per drop</span>
                    <Input v-model.number="filter.units" type="number" min="1" max="1000" class="h-8 w-24" @change="reload" />
                </label>
                <label class="space-y-1">
                    <span class="block text-muted-foreground">Delivery cost per drop ($)</span>
                    <Input v-model="settingsForm.delivery_cost" type="number" min="0" step="0.01" class="h-8 w-28" />
                </label>
                <label class="space-y-1">
                    <span class="block text-muted-foreground">Free delivery from ($)</span>
                    <Input v-model="settingsForm.free_delivery_min" type="number" min="0" step="0.01" class="h-8 w-28" />
                </label>
                <Button type="submit" variant="outline" size="sm" :disabled="settingsForm.processing || !settingsForm.isDirty"
                    >Save delivery settings</Button
                >
                <span class="text-xs text-muted-foreground">Company settings: saved for everyone.</span>
                <span v-if="settingsForm.errors.delivery_cost || settingsForm.errors.free_delivery_min" class="text-red-600">
                    {{ settingsForm.errors.delivery_cost ?? settingsForm.errors.free_delivery_min }}
                </span>
            </form>

            <!-- Modelled drop profitability -->
            <section v-if="tab === 'drops'" class="space-y-3">
                <p class="text-sm text-muted-foreground">
                    A drop of {{ filter.units }} pack(s) of one product, against {{ aud(settings.delivery_cost) }} delivery. Break-even is the number
                    of packs one drop needs for its gross profit to cover the delivery.
                    <strong v-if="losing" class="text-red-600">{{ losing }} product(s) lose money at {{ filter.units }} per drop.</strong>
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr>
                                <th class="py-2">SKU</th>
                                <th>Product</th>
                                <th>Pack</th>
                                <th class="text-right">Sell / pack</th>
                                <th class="text-right">Cost / pack</th>
                                <th class="text-right">GM %</th>
                                <th class="text-right">Sales</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">GP</th>
                                <th class="text-right">Net after delivery</th>
                                <th class="text-right">Break-even packs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in modelled.rows" :key="r.id" class="border-b" :class="{ 'bg-red-50 dark:bg-red-950/40': r.net < 0 }">
                                <td class="py-1.5 font-mono">{{ r.sku }}</td>
                                <td>{{ r.name }}</td>
                                <td>{{ r.pack }}</td>
                                <td class="text-right tabular-nums">{{ aud(r.sell_per_pack) }}</td>
                                <td class="text-right tabular-nums">{{ aud(r.cost_per_pack) }}</td>
                                <td class="text-right tabular-nums">{{ pct(r.gm_percent) }}</td>
                                <td class="text-right tabular-nums">{{ aud(r.sales) }}</td>
                                <td class="text-right tabular-nums">{{ aud(r.cost) }}</td>
                                <td class="text-right tabular-nums">{{ aud(r.gp) }}</td>
                                <td class="text-right font-medium tabular-nums" :class="{ 'text-red-600': r.net < 0 }">{{ aud(r.net) }}</td>
                                <td class="text-right tabular-nums">{{ r.break_even ?? 'never' }}</td>
                            </tr>
                            <tr v-if="!modelled.rows.length">
                                <td colspan="11" class="py-6 text-center text-muted-foreground">No products with both a cost and a sell price.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <details v-if="modelled.missing.length" class="text-sm">
                    <summary class="cursor-pointer text-amber-800 dark:text-amber-300">
                        {{ modelled.missing.length }} product(s) not shown: no cost or no sell price
                    </summary>
                    <ul class="mt-2 columns-2 text-muted-foreground">
                        <li v-for="m in modelled.missing" :key="m.id">
                            <span class="font-mono">{{ m.sku }}</span> {{ m.name }} — {{ flag(m.missing) }}
                        </li>
                    </ul>
                </details>
            </section>

            <!-- Real drops -->
            <section v-if="tab === 'real'" class="space-y-4">
                <form class="flex flex-wrap items-end gap-3 text-sm" @submit.prevent="reload">
                    <label class="space-y-1"
                        ><span class="block text-muted-foreground">Delivered from</span><Input v-model="filter.from" type="date" class="h-8"
                    /></label>
                    <label class="space-y-1"
                        ><span class="block text-muted-foreground">to</span><Input v-model="filter.to" type="date" class="h-8"
                    /></label>
                    <Button type="submit" variant="outline" size="sm">Show</Button>
                </form>
                <p class="text-sm text-muted-foreground">
                    Each dispatched order is one drop. Sales are the packs dispatched at the order's price; cost of goods is the cost when dispatched
                    (orders dispatched before costs were recorded use today's cost and are marked). Orders under
                    {{ aud(settings.free_delivery_min) }} are flagged.
                </p>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">Drops</div>
                        <div class="text-2xl font-semibold tabular-nums">{{ real.totals.drops }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">GP</div>
                        <div class="text-2xl font-semibold tabular-nums">{{ aud(real.totals.gp) }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">Net after delivery</div>
                        <div class="text-2xl font-semibold tabular-nums" :class="{ 'text-red-600': real.totals.net < 0 }">
                            {{ aud(real.totals.net) }}
                        </div>
                    </div>
                    <div class="rounded-lg border p-3">
                        <div class="text-sm text-muted-foreground">Average net per drop</div>
                        <div class="text-2xl font-semibold tabular-nums" :class="{ 'text-red-600': (real.totals.avg_net ?? 0) < 0 }">
                            {{ aud(real.totals.avg_net) }}
                        </div>
                    </div>
                </div>

                <table v-if="real.areas.length" class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-2">Area</th>
                            <th class="text-right">Drops</th>
                            <th class="text-right">Sales</th>
                            <th class="text-right">GP</th>
                            <th class="text-right">Net</th>
                            <th class="text-right">Average net per drop</th>
                            <th class="text-right">Under minimum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in real.areas" :key="a.area" class="border-b">
                            <td class="py-1.5">{{ a.area }}</td>
                            <td class="text-right tabular-nums">{{ a.drops }}</td>
                            <td class="text-right tabular-nums">{{ aud(a.sales) }}</td>
                            <td class="text-right tabular-nums">{{ aud(a.gp) }}</td>
                            <td class="text-right tabular-nums" :class="{ 'text-red-600': a.net < 0 }">{{ aud(a.net) }}</td>
                            <td class="text-right font-medium tabular-nums" :class="{ 'text-red-600': (a.avg_net ?? 0) < 0 }">
                                {{ aud(a.avg_net) }}
                            </td>
                            <td class="text-right tabular-nums">{{ a.under_minimum || '' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr>
                                <th class="py-2">Delivered</th>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Area</th>
                                <th class="text-right">Sales</th>
                                <th class="text-right">Cost of goods</th>
                                <th class="text-right">GP</th>
                                <th class="text-right">Delivery</th>
                                <th class="text-right">Net</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in real.orders" :key="o.id" class="border-b" :class="{ 'bg-red-50 dark:bg-red-950/40': o.net < 0 }">
                                <td class="py-1.5">{{ o.delivery_date }}</td>
                                <td>
                                    <a :href="route('orders.show', o.id)" class="underline">{{ o.number }}</a>
                                </td>
                                <td>{{ o.customer ?? '—' }}</td>
                                <td>{{ o.area }}</td>
                                <td class="text-right tabular-nums">{{ aud(o.sales) }}</td>
                                <td class="text-right tabular-nums">
                                    {{ aud(o.cost) }}
                                    <span
                                        v-if="o.cost_basis !== 'dispatch'"
                                        class="text-xs text-muted-foreground"
                                        title="Cost when dispatched wasn't recorded: today's cost used"
                                    >
                                        {{ o.cost_basis === 'current' ? 'today' : 'part today' }}
                                    </span>
                                </td>
                                <td class="text-right tabular-nums">{{ aud(o.gp) }}</td>
                                <td class="text-right tabular-nums">{{ aud(o.delivery_cost) }}</td>
                                <td class="text-right font-medium tabular-nums" :class="{ 'text-red-600': o.net < 0 }">{{ aud(o.net) }}</td>
                                <td class="space-x-1 text-xs">
                                    <span v-if="o.under_minimum" class="rounded bg-amber-200 px-1 text-amber-900"
                                        >Under {{ aud(settings.free_delivery_min) }}</span
                                    >
                                    <span
                                        v-if="o.price_estimated"
                                        class="text-muted-foreground"
                                        title="A line had no price: the default sell price was used"
                                        >price estimated</span
                                    >
                                </td>
                            </tr>
                            <tr v-if="!real.orders.length">
                                <td colspan="10" class="py-6 text-center text-muted-foreground">No dispatched orders delivered in this range.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
