<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

interface Line {
    product_id: number;
    sku: string;
    name: string;
    range: string | null;
    base_unit: string;
    supplier: { id: number; from: 'product' | 'last_po' | null } | null;
    pack_base: number;
    pack_label: string;
    moq_packs: number | null;
    purchase_unit: { id: number; name: string; base_qty: number } | null;
    avg_daily: number;
    lead_days: number;
    review_days: number;
    cover_days: number;
    safety: number;
    available: number;
    on_order: number;
    need: number;
    suggest_base: number;
    suggest_packs: number;
    cost_per_base: number;
    suggest_value: number;
    days_left: number | null;
    stockout_date: string | null;
    next_delivery: string;
    stockout_before_delivery: boolean;
    sold_4w: number;
    trend: 'up' | 'down' | 'flat';
    sources: Record<string, number>;
    working: string[];
}
interface Group {
    supplier: { id: number; name: string; code: string; lead_time_days: number | null; order_days: number[]; review_period_days: number | null; moq_packs: number | null } | null;
    min_order_value: number | null;
    order_value: number;
    meets_min: boolean;
    to_order: number;
    lines: Line[];
}
interface Settings {
    safety_mode: 'days' | 'z';
    safety_days: number;
    safety_z: number;
    default_lead_days: number;
    default_review_days: number;
    daily_email: boolean;
}

const props = defineProps<{
    filters: { supplier_id: number | null; range_id: number | null; only_needed: boolean; stockout_only: boolean };
    plan: { groups: Group[]; settings: Settings; today: string };
    suppliers: { id: number; name: string }[];
    ranges: { id: number; name: string }[];
    can: { order: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reorder suggestions', href: '/purchasing/reorder' }];
const errors = computed(() => usePage().props.errors as Record<string, string>);
const WEEKDAYS = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const filter = reactive({ ...props.filters });
const reload = () =>
    router.get(
        route('reorder.index'),
        { supplier_id: filter.supplier_id ?? undefined, range_id: filter.range_id ?? undefined, only_needed: filter.only_needed ? 1 : 0, stockout_only: filter.stockout_only ? 1 : 0 },
        { preserveState: true, preserveScroll: true, replace: true },
    );

// Quantities to order, in supplier packs, editable per line. Reset whenever the plan reloads.
const packs = reactive<Record<number, number>>({});
const resetQty = () => {
    for (const g of props.plan.groups) for (const l of g.lines) packs[l.product_id] = l.suggest_packs;
};
resetQty();
watch(() => props.plan, resetQty);

const lineValue = (l: Line) => (packs[l.product_id] ?? 0) * l.pack_base * l.cost_per_base;
const groupValue = (g: Group) => g.lines.reduce((sum, l) => sum + lineValue(l), 0);
const meetsMin = (g: Group) => g.min_order_value === null || groupValue(g) >= g.min_order_value;
const money = (n: number) => `$${n.toFixed(2)}`;
const qty = (n: number, l: Line) => `${Number.isInteger(n) ? n : n.toFixed(1)} ${l.base_unit}`;
const trendIcon = (t: Line['trend']) => ({ up: '↑', down: '↓', flat: '→' })[t];
const sourceText = (l: Line) =>
    Object.entries(l.sources)
        .map(([k, v]) => `${k} ${v}`)
        .join(' · ') || 'no sales in 8 weeks';
const supplierRule = (g: Group) => {
    const s = g.supplier;
    if (!s) return '';
    const when = s.order_days.length ? `orders ${s.order_days.map((d) => WEEKDAYS[d]).join('/')}` : s.review_period_days !== null ? `review every ${s.review_period_days} days` : 'default review period';
    return [`lead ${s.lead_time_days ?? props.plan.settings.default_lead_days} days`, when, s.moq_packs ? `MOQ ${s.moq_packs}` : null].filter(Boolean).join(' · ');
};

const drafting = ref<number | null>(null);
const createDraft = (g: Group) => {
    if (!g.supplier) return;
    const lines = g.lines.map((l) => ({ product_id: l.product_id, qty_base: Math.max(0, Math.round(packs[l.product_id] ?? 0)) * l.pack_base })).filter((l) => l.qty_base > 0);
    if (!lines.length) return;
    drafting.value = g.supplier.id;
    router.post(route('reorder.draft'), { supplier_id: g.supplier.id, lines }, { onFinish: () => (drafting.value = null) });
};
const orderable = (g: Group) => g.lines.some((l) => (packs[l.product_id] ?? 0) > 0);

const showSettings = ref(false);
const settingsForm = useForm({ ...props.plan.settings });
const saveSettings = () => settingsForm.put(route('reorder.settings'), { preserveScroll: true, onSuccess: () => (showSettings.value = false) });
</script>

<template>
    <Head title="Reorder suggestions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-end gap-3">
                <h1 class="mr-auto text-xl font-semibold">Reorder suggestions</h1>
                <label class="text-sm">
                    <span class="block text-muted-foreground">Supplier</span>
                    <select v-model="filter.supplier_id" class="h-9 rounded-md border bg-background px-2" @change="reload">
                        <option :value="null">All</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-muted-foreground">Range</span>
                    <select v-model="filter.range_id" class="h-9 rounded-md border bg-background px-2" @change="reload">
                        <option :value="null">All</option>
                        <option v-for="r in ranges" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                </label>
                <label class="flex h-9 items-center gap-2 text-sm"><input v-model="filter.only_needed" type="checkbox" @change="reload" /> Only items that need ordering</label>
                <label class="flex h-9 items-center gap-2 text-sm"><input v-model="filter.stockout_only" type="checkbox" @change="reload" /> Stock-out before next delivery</label>
                <Button v-if="can.order" variant="outline" size="sm" @click="showSettings = !showSettings">Settings</Button>
            </header>
            <p class="text-xs text-muted-foreground">
                Demand is a weighted average of the last 8 weeks (recent weeks count more), leaving out days with no stock. Need = demand × (lead time + days to the next order) + safety
                stock − (available + on order). Rounded up to the supplier pack and MOQ. Hover a line for its working. Value at cost, ex GST. Nothing is sent: drafts open on the PO page.
            </p>

            <form v-if="showSettings" class="grid gap-3 rounded-lg border p-4 text-sm sm:grid-cols-3" @submit.prevent="saveSettings">
                <label>
                    <span class="block font-medium">Safety stock</span>
                    <select v-model="settingsForm.safety_mode" class="h-9 rounded-md border bg-background px-2">
                        <option value="days">Days of demand</option>
                        <option value="z">z × std-dev × √lead time</option>
                    </select>
                </label>
                <label v-if="settingsForm.safety_mode === 'days'">
                    <span class="block font-medium">Safety days</span>
                    <input v-model.number="settingsForm.safety_days" type="number" min="0" step="0.5" class="h-9 w-24 rounded-md border bg-background px-2" />
                </label>
                <label v-else>
                    <span class="block font-medium">z (1.65 ≈ 95% service)</span>
                    <input v-model.number="settingsForm.safety_z" type="number" min="0" step="0.05" class="h-9 w-24 rounded-md border bg-background px-2" />
                </label>
                <label>
                    <span class="block font-medium">Lead time when a supplier has none (days)</span>
                    <input v-model.number="settingsForm.default_lead_days" type="number" min="0" class="h-9 w-24 rounded-md border bg-background px-2" />
                </label>
                <label>
                    <span class="block font-medium">Review period when a supplier has none (days)</span>
                    <input v-model.number="settingsForm.default_review_days" type="number" min="0" class="h-9 w-24 rounded-md border bg-background px-2" />
                </label>
                <label class="flex items-center gap-2 self-end"><input v-model="settingsForm.daily_email" type="checkbox" /> Email managers a summary at 7am</label>
                <div class="self-end"><Button type="submit" size="sm" :disabled="settingsForm.processing">Save settings</Button></div>
                <InputError :message="Object.values(settingsForm.errors)[0]" class="sm:col-span-3" />
            </form>

            <InputError :message="errors.reorder" />
            <p v-if="!plan.groups.length" class="py-6 text-center text-sm text-muted-foreground">Nothing to show with these filters.</p>

            <section v-for="g in plan.groups" :key="g.supplier?.id ?? 0" class="rounded-lg border">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b p-3">
                    <h2 class="font-semibold">{{ g.supplier?.name ?? 'No supplier set' }}</h2>
                    <span class="text-xs text-muted-foreground">{{ supplierRule(g) }}</span>
                    <span class="ml-auto text-sm tabular-nums">Order value {{ money(groupValue(g)) }}</span>
                    <span v-if="g.min_order_value !== null" class="rounded px-1.5 py-0.5 text-xs" :class="meetsMin(g) ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200' : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'">
                        {{ meetsMin(g) ? 'meets' : 'below' }} minimum {{ money(g.min_order_value) }}
                    </span>
                    <Button v-if="can.order && g.supplier" size="sm" :disabled="!orderable(g) || drafting !== null" @click="createDraft(g)">
                        {{ drafting === g.supplier.id ? 'Creating…' : 'Create draft PO' }}
                    </Button>
                    <span v-else-if="!g.supplier" class="text-xs text-muted-foreground">Set a preferred supplier on these products to order them.</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[64rem] text-left text-sm">
                        <thead class="border-b text-xs text-muted-foreground">
                            <tr>
                                <th class="p-2">Product</th>
                                <th class="text-right">Per day</th>
                                <th class="text-right">Last 4 wks</th>
                                <th class="text-right">Available</th>
                                <th class="text-right">On order</th>
                                <th class="text-right">Days left</th>
                                <th>Runs out</th>
                                <th class="text-right">Need</th>
                                <th class="text-right">Order (packs)</th>
                                <th class="pr-2 text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in g.lines" :key="l.product_id" class="border-b align-top" :title="l.working.join('\n')">
                                <td class="p-2">
                                    <span class="font-mono">{{ l.sku }}</span> {{ l.name }}
                                    <div class="text-xs text-muted-foreground">
                                        {{ l.range ?? 'No range' }} · pack {{ l.pack_label }}<template v-if="l.supplier?.from === 'last_po'"> · supplier from last PO</template> · {{ sourceText(l) }}
                                    </div>
                                </td>
                                <td class="text-right tabular-nums">{{ l.avg_daily }}</td>
                                <td class="text-right tabular-nums">{{ l.sold_4w }} <span :class="{ 'text-green-700 dark:text-green-300': l.trend === 'up', 'text-red-700 dark:text-red-300': l.trend === 'down' }" :title="`Trend: ${l.trend}`">{{ trendIcon(l.trend) }}</span></td>
                                <td class="text-right tabular-nums" :class="{ 'text-red-700 dark:text-red-300': l.available < 0 }">{{ l.available }}</td>
                                <td class="text-right tabular-nums">{{ l.on_order || '' }}</td>
                                <td class="text-right tabular-nums">{{ l.days_left ?? '—' }}</td>
                                <td :class="{ 'font-semibold text-red-700 dark:text-red-300': l.stockout_before_delivery }">
                                    {{ l.stockout_date ?? '—' }}
                                    <div v-if="l.stockout_before_delivery" class="text-xs font-normal">before delivery {{ l.next_delivery }}</div>
                                </td>
                                <td class="text-right tabular-nums">{{ l.need > 0 ? qty(l.need, l) : '—' }}</td>
                                <td class="text-right">
                                    <input
                                        v-if="can.order && g.supplier"
                                        v-model.number="packs[l.product_id]"
                                        type="number"
                                        min="0"
                                        class="h-8 w-20 rounded-md border bg-background px-2 text-right tabular-nums"
                                        :aria-label="`Packs of ${l.sku}`"
                                    />
                                    <span v-else class="tabular-nums">{{ l.suggest_packs }}</span>
                                    <div class="text-xs text-muted-foreground">= {{ (packs[l.product_id] ?? 0) * l.pack_base }} {{ l.base_unit }}</div>
                                </td>
                                <td class="pr-2 text-right tabular-nums">{{ money(lineValue(l)) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <p class="text-xs text-muted-foreground">
                Prefer to set the usual supplier, lead time and supplier pack on each product; without a supplier, the last PO's is used.
                <Link :href="route('suppliers.index')" class="underline">Suppliers</Link> hold order days, minimum order and MOQ.
            </p>
        </div>
    </AppLayout>
</template>
