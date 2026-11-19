<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type GroupBy = 'reason' | 'product' | 'range' | 'supplier';
interface Group {
    key: string;
    label: string;
    value: number;
    units: number;
    lines: number;
    share: number | null;
}
interface Row {
    id: number;
    date: string;
    product_id: number;
    sku: string | null;
    name: string | null;
    base_unit: string | null;
    range: string | null;
    supplier: string | null;
    reason: string | null;
    bin: string | null;
    batch: string | null;
    best_before: string | null;
    units: number;
    unit_cost: number | null;
    value: number;
    cost_basis: 'movement' | 'current' | 'none';
    note: string | null;
    by: string | null;
}

const props = defineProps<{
    filters: { from: string; to: string; period: 'week' | 'month'; group: GroupBy };
    report: {
        rows: Row[];
        totals: { value: number; units: number; lines: number; at_current_cost: number; no_cost: number; cogs: number; percent_of_cogs: number | null };
        groups: Record<GroupBy, Group[]>;
        trend: { start: string; label: string; value: number; units: number }[];
        top: Group[];
        period: 'week' | 'month';
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Spoilage', href: '/reports/spoilage' }];
const filter = reactive({ ...props.filters });
const reload = () => router.get(route('reports.spoilage.index'), { ...filter }, { preserveState: true, preserveScroll: true, replace: true });
const exportUrl = (format: 'xlsx' | 'csv') => route('reports.spoilage.export', { format, ...filter });
const money = (n: number | null) => (n === null ? '—' : `$${n.toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
const groupLabels: Record<GroupBy, string> = { reason: 'Reason', product: 'Product', range: 'Range', supplier: 'Supplier' };
const showLines = ref(false);

// Trend chart: one series, bars from a zero baseline.
const W = 640;
const H = 180;
const PAD = { l: 48, r: 8, t: 8, b: 24 };
const maxValue = computed(() => Math.max(1, ...props.report.trend.map((t) => t.value)));
const niceMax = computed(() => {
    const m = maxValue.value;
    const step = Math.pow(10, Math.floor(Math.log10(m)));
    return Math.ceil(m / step) * step;
});
const ticks = computed(() => [0, 0.5, 1].map((f) => f * niceMax.value));
const slot = computed(() => (W - PAD.l - PAD.r) / Math.max(1, props.report.trend.length));
const barW = computed(() => Math.max(2, Math.min(28, slot.value - 4)));
const y = (v: number) => PAD.t + (H - PAD.t - PAD.b) * (1 - v / niceMax.value);
/** A bar with 4px rounded top corners, square at the baseline. */
const barPath = (i: number, v: number) => {
    const x = PAD.l + i * slot.value + (slot.value - barW.value) / 2;
    const top = y(v);
    const base = y(0);
    const r = Math.min(4, barW.value / 2, base - top);
    if (base - top < 0.5) return '';
    return `M${x},${base} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW.value - r} Q${x + barW.value},${top} ${x + barW.value},${top + r} V${base} Z`;
};
const labelEvery = computed(() => Math.ceil(props.report.trend.length / 10));
const hover = ref<number | null>(null);
</script>

<template>
    <Head title="Spoilage" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4">
            <header class="flex flex-wrap items-end gap-3">
                <h1 class="mr-auto text-xl font-semibold">Spoilage</h1>
                <label class="text-sm">
                    <span class="block text-muted-foreground">From</span>
                    <input v-model="filter.from" type="date" class="h-9 rounded-md border bg-background px-2" @change="reload" />
                </label>
                <label class="text-sm">
                    <span class="block text-muted-foreground">To</span>
                    <input v-model="filter.to" type="date" class="h-9 rounded-md border bg-background px-2" @change="reload" />
                </label>
                <label class="text-sm">
                    <span class="block text-muted-foreground">Trend by</span>
                    <select v-model="filter.period" class="h-9 rounded-md border bg-background px-2" @change="reload">
                        <option value="week">Week</option>
                        <option value="month">Month</option>
                    </select>
                </label>
                <div class="text-sm">Export: <a :href="exportUrl('xlsx')" class="underline">XLSX</a> <a :href="exportUrl('csv')" class="underline">CSV</a></div>
            </header>
            <p class="text-xs text-muted-foreground">
                Every write-off in these dates, ex GST, at the average cost when it was written off. Older write-offs kept no cost and are valued at today's
                average cost (marked "today"). Spoilage % = write-offs ÷ cost of goods dispatched in the same dates.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border p-4">
                    <div class="text-sm text-muted-foreground">Written off</div>
                    <div class="text-3xl font-semibold tabular-nums">{{ money(report.totals.value) }}</div>
                    <div class="text-xs text-muted-foreground">
                        {{ report.totals.lines }} write-off{{ report.totals.lines === 1 ? '' : 's' }}
                        <template v-if="report.totals.at_current_cost"> · {{ report.totals.at_current_cost }} at today's cost</template>
                        <template v-if="report.totals.no_cost"> · {{ report.totals.no_cost }} with no cost</template>
                    </div>
                </div>
                <div class="rounded-lg border p-4">
                    <div class="text-sm text-muted-foreground">Units</div>
                    <div class="text-3xl font-semibold tabular-nums">{{ report.totals.units.toLocaleString('en-AU') }}</div>
                    <div class="text-xs text-muted-foreground">base units (each, kg…)</div>
                </div>
                <div class="rounded-lg border p-4">
                    <div class="text-sm text-muted-foreground">Of cost of goods sold</div>
                    <div class="text-3xl font-semibold tabular-nums">{{ report.totals.percent_of_cogs === null ? '—' : `${report.totals.percent_of_cogs}%` }}</div>
                    <div class="text-xs text-muted-foreground">cost of goods sold {{ money(report.totals.cogs) }}</div>
                </div>
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Written off per {{ report.period }}</h2>
                <svg :viewBox="`0 0 ${W} ${H}`" class="w-full max-w-3xl text-muted-foreground" role="img" :aria-label="`Value written off per ${report.period}`">
                    <g v-for="t in ticks" :key="t">
                        <line :x1="PAD.l" :x2="W - PAD.r" :y1="y(t)" :y2="y(t)" stroke="currentColor" stroke-opacity="0.2" />
                        <text :x="PAD.l - 6" :y="y(t) + 4" text-anchor="end" class="fill-current text-[10px] tabular-nums">${{ t >= 1000 ? `${t / 1000}k` : t }}</text>
                    </g>
                    <g v-for="(t, i) in report.trend" :key="t.start" @mouseenter="hover = i" @mouseleave="hover = null">
                        <!-- hit target: the full column, wider than the bar -->
                        <rect :x="PAD.l + i * slot" :y="PAD.t" :width="slot" :height="H - PAD.t - PAD.b" fill="transparent" />
                        <path :d="barPath(i, t.value)" class="fill-sky-600 dark:fill-sky-400" :fill-opacity="hover === null || hover === i ? 1 : 0.5" />
                        <text
                            v-if="i % labelEvery === 0"
                            :x="PAD.l + i * slot + slot / 2"
                            :y="H - 8"
                            text-anchor="middle"
                            class="fill-current text-[10px]"
                        >
                            {{ t.label }}
                        </text>
                    </g>
                </svg>
                <div class="h-5 text-sm tabular-nums">
                    <template v-if="hover !== null">
                        {{ report.period === 'week' ? 'Week of' : '' }} {{ report.trend[hover].label }}: <strong>{{ money(report.trend[hover].value) }}</strong>,
                        {{ report.trend[hover].units }} units
                    </template>
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-lg border p-4">
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <h2 class="mr-auto font-semibold">By {{ groupLabels[filter.group].toLowerCase() }}</h2>
                        <button
                            v-for="(label, g) in groupLabels"
                            :key="g"
                            type="button"
                            class="rounded-md border px-2 py-0.5 text-sm"
                            :class="filter.group === g ? 'bg-muted font-medium' : ''"
                            @click="filter.group = g"
                        >
                            {{ label }}
                        </button>
                    </div>
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr><th class="py-1">{{ groupLabels[filter.group] }}</th><th class="text-right">Value</th><th class="text-right">Units</th><th class="text-right">Share</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="g in report.groups[filter.group]" :key="g.key" class="border-b">
                                <td class="py-1">{{ g.label }}</td>
                                <td class="text-right tabular-nums">{{ money(g.value) }}</td>
                                <td class="text-right tabular-nums">{{ g.units }}</td>
                                <td class="text-right tabular-nums">{{ g.share === null ? '—' : `${g.share}%` }}</td>
                            </tr>
                            <tr v-if="!report.groups[filter.group].length">
                                <td colspan="4" class="py-4 text-center text-muted-foreground">Nothing written off in these dates.</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">Top 10 products by $ lost</h2>
                    <ol class="space-y-1 text-sm">
                        <li v-for="g in report.top" :key="g.key" class="flex items-center gap-2">
                            <span class="w-2/5 truncate" :title="g.label">{{ g.label }}</span>
                            <span class="h-3 flex-1 rounded-sm bg-muted">
                                <span class="block h-3 rounded-sm bg-sky-600 dark:bg-sky-400" :style="{ width: `${(g.value / (report.top[0]?.value || 1)) * 100}%` }"></span>
                            </span>
                            <span class="w-20 text-right tabular-nums">{{ money(g.value) }}</span>
                        </li>
                        <li v-if="!report.top.length" class="py-4 text-center text-muted-foreground">Nothing written off in these dates.</li>
                    </ol>
                </section>
            </div>

            <section>
                <button type="button" class="text-sm underline" @click="showLines = !showLines">
                    {{ showLines ? 'Hide' : 'Show' }} every write-off ({{ report.rows.length }})
                </button>
                <div v-if="showLines" class="mt-2 overflow-x-auto">
                    <table class="w-full min-w-[60rem] text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr>
                                <th class="py-2">Date</th><th>Product</th><th>Reason</th><th>Bin / batch</th>
                                <th class="text-right">Units</th><th class="text-right">Cost / unit</th><th class="text-right">Value</th><th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in report.rows" :key="r.id" class="border-b align-top">
                                <td class="whitespace-nowrap py-1 tabular-nums">{{ r.date }}</td>
                                <td><Link :href="route('stock.show', r.product_id)" class="font-mono underline">{{ r.sku }}</Link> {{ r.name }}</td>
                                <td>{{ r.reason ?? '—' }}</td>
                                <td class="text-xs">{{ r.bin }}<div v-if="r.batch" class="text-muted-foreground">{{ r.batch }} <template v-if="r.best_before">BB {{ r.best_before }}</template></div></td>
                                <td class="text-right tabular-nums">{{ r.units }} {{ r.base_unit }}</td>
                                <td class="text-right tabular-nums">
                                    {{ money(r.unit_cost) }}
                                    <span v-if="r.cost_basis === 'current'" class="ml-1 rounded bg-amber-100 px-1 text-xs text-amber-900" title="No cost was kept; valued at today's average cost">today</span>
                                    <span v-if="r.cost_basis === 'none'" class="ml-1 rounded bg-red-100 px-1 text-xs text-red-800">no cost</span>
                                </td>
                                <td class="text-right tabular-nums">{{ money(r.value) }}</td>
                                <td class="text-xs">{{ r.note }}<div class="text-muted-foreground">{{ r.by }}</div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
