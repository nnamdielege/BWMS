<script setup lang="ts">
import BarTrend from '@/components/reports/BarTrend.vue';
import DataTable from '@/components/reports/DataTable.vue';
import InsightFilterBar from '@/components/reports/InsightFilterBar.vue';
import StatTile from '@/components/reports/StatTile.vue';
import type { Column, InsightFilters, InsightOptions, Row } from '@/components/reports/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, num, pct } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type GroupBy = 'sku' | 'range' | 'customer' | 'channel';
type Top = 'products' | 'products_bottom' | 'customers' | 'customers_bottom';
const props = defineProps<{
    filters: InsightFilters;
    options: InsightOptions;
    group: GroupBy;
    report: {
        totals: Record<string, number | null>;
        groups: Record<GroupBy, Row[]>;
        trend: { start: string; label: string; sales: number; gp: number; units: number; change_percent: number | null }[];
        top: Record<Top, Row[]>;
        lapsed: Row[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stock sold', href: '/reports/sold' }];
const group = ref<GroupBy>(props.group);
const top = ref<Top>('products');
const t = computed(() => props.report.totals);
const labels: Record<GroupBy, string> = { sku: 'SKU', range: 'Range', customer: 'Customer', channel: 'Channel' };
const topLabels: Record<Top, string> = {
    products: 'Top products',
    products_bottom: 'Bottom products',
    customers: 'Top customers',
    customers_bottom: 'Bottom customers',
};

const figures: Column[] = [
    { key: 'units', label: 'Units', kind: 'int' },
    { key: 'sales', label: 'Sales', kind: 'money' },
    { key: 'cost', label: 'Cost', kind: 'money' },
    { key: 'gp', label: 'GP', kind: 'money', cellClass: (r) => (r.gp < 0 ? 'text-red-700 dark:text-red-400' : '') },
    { key: 'gm_percent', label: 'GM', kind: 'pct' },
    { key: 'orders', label: 'Orders', kind: 'int' },
];
const groupColumns = computed<Column[]>(() => [
    { key: 'label', label: labels[group.value], href: (r) => (r.product_id ? route('stock.show', r.product_id) : null) },
    ...figures,
]);
const topColumns = computed<Column[]>(() => [
    {
        key: 'label',
        label: top.value.startsWith('products') ? 'Product' : 'Customer',
        href: (r) => (r.product_id ? route('stock.show', r.product_id) : null),
    },
    ...figures,
]);
const lapsedColumns: Column[] = [
    { key: 'customer', label: 'Customer', sub: (r) => (r.still_ordering ? 'still orders other things' : 'no orders since') },
    { key: 'sku', label: 'Product', sub: (r) => r.name, href: (r) => route('stock.show', r.product_id) },
    { key: 'orders', label: 'Orders', kind: 'int' },
    { key: 'usual_gap_days', label: 'Usual gap (days)' },
    { key: 'last_ordered', label: 'Last ordered' },
    { key: 'days_since', label: 'Days since', kind: 'int' },
    { key: 'avg_order_sales', label: 'Avg per order', kind: 'money' },
];
const trendPoints = computed(() =>
    props.report.trend.map((w) => ({
        label: w.label,
        value: w.sales,
        detail: `GP ${money(w.gp)}${w.change_percent === null ? '' : `, ${w.change_percent > 0 ? '+' : ''}${w.change_percent}% on the week before`}`,
    })),
);
</script>

<template>
    <Head title="Stock sold" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4">
            <h1 class="text-xl font-semibold">Stock sold</h1>
            <InsightFilterBar
                :filters="filters"
                :options="options"
                index-route="reports.sold.index"
                export-route="reports.sold.export"
                :extra="{ group }"
            />
            <p class="text-xs text-muted-foreground">
                Stock dispatched in these dates, ex GST. Sales at each order line's price; cost at dispatch (today's average cost for older
                dispatches).
                <template v-if="t.estimated"> {{ t.estimated }} line(s) had no price and use the default price.</template>
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Sales" :value="money(t.sales)" :sub="`${num(t.orders)} orders · ${num(t.customers)} customers`" />
                <StatTile label="Cost of goods" :value="money(t.cost)" :sub="`${num(t.units)} units`" />
                <StatTile label="Gross profit" :value="money(t.gp)" />
                <StatTile label="Gross margin" :value="pct(t.gm_percent)" />
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Sales per week</h2>
                <BarTrend :points="trendPoints" title="Sales per week" :format="money" />
            </section>

            <section class="rounded-lg border p-4">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto font-semibold">By {{ labels[group].toLowerCase() }}</h2>
                    <button
                        v-for="(label, g) in labels"
                        :key="g"
                        type="button"
                        class="rounded-md border px-2 py-0.5 text-sm"
                        :class="group === g ? 'bg-muted font-medium' : ''"
                        @click="group = g"
                    >
                        {{ label }}
                    </button>
                </div>
                <DataTable :columns="groupColumns" :rows="report.groups[group]" empty="Nothing dispatched in these dates." />
            </section>

            <section class="rounded-lg border p-4">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto font-semibold">{{ topLabels[top] }} by GP</h2>
                    <button
                        v-for="(label, k) in topLabels"
                        :key="k"
                        type="button"
                        class="rounded-md border px-2 py-0.5 text-sm"
                        :class="top === k ? 'bg-muted font-medium' : ''"
                        @click="top = k"
                    >
                        {{ label }}
                    </button>
                </div>
                <DataTable :columns="topColumns" :rows="report.top[top]" :limit="10" empty="Nothing dispatched in these dates." />
            </section>

            <section class="rounded-lg border p-4">
                <h2 class="font-semibold">Stopped ordering a product they used to buy</h2>
                <p class="mb-2 text-xs text-muted-foreground">
                    Bought on 3 or more orders in the 26 weeks to {{ filters.to }}, and not for more than twice their usual gap (at least 4 weeks).
                </p>
                <DataTable :columns="lapsedColumns" :rows="report.lapsed" empty="No one has stopped buying a regular product." />
            </section>
        </div>
    </AppLayout>
</template>
