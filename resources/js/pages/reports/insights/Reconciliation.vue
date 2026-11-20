<script setup lang="ts">
import DataTable from '@/components/reports/DataTable.vue';
import InsightFilterBar from '@/components/reports/InsightFilterBar.vue';
import StatTile from '@/components/reports/StatTile.vue';
import type { Column, InsightFilters, InsightOptions, Row } from '@/components/reports/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, num, pct } from '@/lib/money';
import { movementsHref } from '@/lib/movements';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    filters: InsightFilters;
    options: InsightOptions;
    report: { rows: Row[]; ranges: Row[]; totals: Record<string, number | null>; top: Row[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stock reconciliation', href: '/reports/reconciliation' }];
const t = computed(() => props.report.totals);
const onlyLoss = ref(false);
const rows = computed(() => (onlyLoss.value ? props.report.rows.filter((r) => r.loss_units > 0 || r.difference !== 0) : props.report.rows));

const lossColumn: Column = {
    key: 'loss_value',
    label: 'Unexplained $',
    kind: 'money',
    href: (r) => movementsHref(r.loss_movement_ids),
    sub: (r) => (r.loss_units ? `${r.loss_units} units` : null),
    cellClass: (r) => (r.loss_value > 0 ? 'text-red-700 dark:text-red-400' : ''),
};
const columns: Column[] = [
    { key: 'sku', label: 'SKU', sub: (r) => r.name, href: (r) => route('stock.show', r.product_id) },
    { key: 'opening', label: 'Opening', kind: 'int' },
    { key: 'received', label: '+ Received', kind: 'int' },
    { key: 'dispatched', label: '− Dispatched', kind: 'int' },
    { key: 'write_offs', label: '− Write-offs', kind: 'int' },
    { key: 'giveaways', label: '− Give-aways', kind: 'int' },
    { key: 'adjustments', label: '± Adjust', kind: 'int' },
    { key: 'stocktake', label: '± Stocktake', kind: 'int' },
    { key: 'other', label: '± Other', kind: 'int' },
    { key: 'expected', label: '= Expected', kind: 'int' },
    { key: 'actual', label: 'Actual', kind: 'int', cellClass: (r) => (r.difference ? 'font-semibold text-amber-700 dark:text-amber-400' : '') },
    lossColumn,
];
const rangeColumns: Column[] = [
    { key: 'range', label: 'Range' },
    { key: 'loss_units', label: 'Unexplained units', kind: 'int' },
    { key: 'loss_value', label: 'Unexplained $', kind: 'money' },
    { key: 'cogs', label: 'Cost of goods sold', kind: 'money' },
    { key: 'shrinkage_percent', label: 'Shrinkage', kind: 'pct' },
];
</script>

<template>
    <Head title="Stock reconciliation" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4">
            <h1 class="text-xl font-semibold">Stock reconciliation</h1>
            <InsightFilterBar
                :filters="filters"
                :options="options"
                index-route="reports.reconciliation.index"
                export-route="reports.reconciliation.export"
            />
            <p class="text-xs text-muted-foreground">
                Base units. Opening + received − dispatched − write-offs − give-aways ± adjustments ± stocktake variances ± other (transfers, returns)
                = expected closing, against the actual closing. <strong>Unexplained loss</strong> = negative stocktake variances plus negative
                adjustments with no supporting reason (no reason, "Lost / missing", or no note), at cost. Click a figure to see the movements behind
                it.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Unexplained loss"
                    :value="money(t.loss_value)"
                    :sub="`${num(t.loss_units)} units`"
                    :tone="(t.loss_value ?? 0) > 0 ? 'warn' : null"
                />
                <StatTile label="Shrinkage" :value="pct(t.shrinkage_percent)" :sub="`of cost of goods sold ${money(t.cogs)}`" />
                <StatTile
                    label="Top loss SKU"
                    :value="report.top[0] ? report.top[0].sku : '—'"
                    :sub="report.top[0] ? `${money(report.top[0].loss_value)} · ${report.top[0].name}` : 'no unexplained loss'"
                />
                <StatTile
                    label="Books vs balances"
                    :value="t.mismatched ? `${t.mismatched} differ` : 'Agree'"
                    :sub="t.mismatched ? 'expected and actual closing differ: tell support' : 'every SKU reconciles'"
                    :tone="t.mismatched ? 'warn' : null"
                />
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Shrinkage by range</h2>
                <DataTable :columns="rangeColumns" :rows="report.ranges" empty="No stock moved in these dates." />
            </section>

            <section class="rounded-lg border p-4">
                <div class="mb-2 flex flex-wrap items-center gap-3">
                    <h2 class="mr-auto font-semibold">Per SKU, biggest unexplained loss first</h2>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="onlyLoss" type="checkbox" /> Only SKUs with a loss or difference</label
                    >
                </div>
                <DataTable :columns="columns" :rows="rows" :limit="100" empty="No stock moved in these dates." />
            </section>
        </div>
    </AppLayout>
</template>
