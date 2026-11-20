<script setup lang="ts">
import BarTrend from '@/components/reports/BarTrend.vue';
import DataTable from '@/components/reports/DataTable.vue';
import InsightFilterBar from '@/components/reports/InsightFilterBar.vue';
import StatTile from '@/components/reports/StatTile.vue';
import type { Column, InsightFilters, InsightOptions, Row } from '@/components/reports/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { num } from '@/lib/money';
import { movementsHref } from '@/lib/movements';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    filters: InsightFilters;
    options: InsightOptions;
    report: {
        work_hours: string;
        large_variance: number;
        totals: Record<string, number>;
        by_user: Row[];
        by_reason: Row[];
        by_hour: { hour: number; label: string; count: number; after_hours: boolean }[];
        out_of_hours: Row[];
        repeated: { skus: Row[]; bins: Row[] };
        negative_bins: Row[];
        short_picks: Row[];
        unscanned_picks: Row[];
        self_counted: Row[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Exceptions & audit', href: '/reports/exceptions' }];
const t = computed(() => props.report.totals);
const [startHour, endHour] = props.report.work_hours.split('-').map((h) => parseInt(h, 10));
const settings = useForm({ start_hour: startHour, end_hour: endHour, large_variance: props.report.large_variance });
const showSettings = ref(false);
const saveSettings = () =>
    settings.put(route('reports.exceptions.settings'), { preserveScroll: true, onSuccess: () => (showSettings.value = false) });
const flagged = 'font-semibold text-amber-700 dark:text-amber-400';

const summaryColumns = (label: string): Column[] => [
    { key: 'label', label },
    { key: 'count', label: 'Count', kind: 'int', href: (r) => movementsHref(r.ids) },
    { key: 'units_out', label: 'Units out', kind: 'int' },
    { key: 'value_out', label: '$ out', kind: 'money' },
    { key: 'units_in', label: 'Units in', kind: 'int' },
    {
        key: 'after_hours',
        label: 'After hours / weekend',
        href: (r) => movementsHref(r.flag_ids),
        sub: (r) => (r.weekend ? `${r.weekend} at the weekend` : null),
        cellClass: (r) => (r.after_hours || r.weekend ? flagged : ''),
    },
];
const userColumns = summaryColumns('User');
const reasonColumns = summaryColumns('Reason');
const outColumns: Column[] = [
    {
        key: 'when',
        label: 'When',
        sub: (r) => [r.day, r.after_hours ? 'after hours' : null, r.weekend ? 'weekend' : null].filter(Boolean).join(' · '),
    },
    { key: 'user', label: 'User' },
    { key: 'reason', label: 'Reason', sub: (r) => r.type },
    { key: 'sku', label: 'SKU', sub: (r) => r.name },
    { key: 'bin', label: 'Bin' },
    { key: 'qty', label: 'Qty', kind: 'int', href: (r) => movementsHref([r.id]) },
    { key: 'value', label: 'Value', kind: 'money' },
    { key: 'note', label: 'Note' },
];
const repeatSku: Column[] = [
    { key: 'sku', label: 'SKU', sub: (r) => r.name },
    { key: 'times', label: 'Times', kind: 'int', href: (r) => movementsHref(r.ids) },
    { key: 'units', label: 'Units', kind: 'int' },
    { key: 'value', label: 'Value', kind: 'money' },
];
const repeatBin: Column[] = [{ key: 'bin', label: 'Bin' }, ...repeatSku.slice(1)];
const negativeColumns: Column[] = [
    { key: 'bin', label: 'Bin' },
    { key: 'sku', label: 'SKU', sub: (r) => r.name, href: (r) => route('stock.show', r.product_id) },
    { key: 'lowest', label: 'Went to', kind: 'int', href: (r) => movementsHref(r.ids), cellClass: () => 'text-red-700 dark:text-red-400' },
    { key: 'times', label: 'Times raised', kind: 'int' },
    { key: 'on_hand_now', label: 'Now', kind: 'int' },
    { key: 'last', label: 'Last', sub: (r) => (r.count_task ? `count task ${r.count_task}` : null) },
];
const pickListHref = (r: Row) => (r.pick_list_ids.length ? route('pick-lists.show', r.pick_list_ids[0]) : null);
const shortColumns: Column[] = [
    { key: 'picker', label: 'Picker' },
    { key: 'sku', label: 'SKU', sub: (r) => r.name },
    { key: 'lines', label: 'Lines', kind: 'int' },
    { key: 'units_short', label: 'Units short', kind: 'int' },
    { key: 'pick_lists', label: 'Pick lists', href: pickListHref },
];
const unscannedColumns: Column[] = [
    { key: 'picker', label: 'Picker' },
    { key: 'reason', label: 'Why not scanned' },
    { key: 'lines', label: 'Lines', kind: 'int' },
    { key: 'skus', label: 'SKUs' },
    { key: 'pick_lists', label: 'Pick lists', href: pickListHref },
];
const selfColumns: Column[] = [
    { key: 'stocktake', label: 'Stocktake', href: (r) => route('stocktakes.show', r.stocktake_id) },
    { key: 'user', label: 'Counted and posted by' },
    { key: 'sku', label: 'SKU', sub: (r) => r.name },
    { key: 'bin', label: 'Bin' },
    { key: 'variance', label: 'Variance', kind: 'int', href: (r) => movementsHref(r.ids) },
    { key: 'value', label: 'Value', kind: 'money', cellClass: (r) => (r.value < 0 ? 'text-red-700 dark:text-red-400' : '') },
];
const list = (r: Row, key: string) => (Array.isArray(r[key]) ? r[key].join(', ') : r[key]);
const asText = (rows: Row[], keys: string[]) => rows.map((r) => ({ ...r, ...Object.fromEntries(keys.map((k) => [k, list(r, k)])) }));
const hourPoints = computed(() =>
    props.report.by_hour.map((h) => ({ label: h.label, value: h.count, detail: h.after_hours ? 'after hours' : 'working hours' })),
);
</script>

<template>
    <Head title="Exceptions & audit" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="mr-auto text-xl font-semibold">Exceptions &amp; audit</h1>
                <button type="button" class="text-sm underline" @click="showSettings = !showSettings">Settings</button>
            </div>
            <form v-if="showSettings" class="flex flex-wrap items-end gap-3 rounded-lg border p-4 text-sm" @submit.prevent="saveSettings">
                <label>
                    <span class="block text-muted-foreground">Working hours from</span>
                    <input
                        v-model.number="settings.start_hour"
                        type="number"
                        min="0"
                        max="23"
                        class="h-9 w-20 rounded-md border bg-background px-2"
                    />
                </label>
                <label>
                    <span class="block text-muted-foreground">to (hour)</span>
                    <input v-model.number="settings.end_hour" type="number" min="1" max="24" class="h-9 w-20 rounded-md border bg-background px-2" />
                </label>
                <label>
                    <span class="block text-muted-foreground">Large stocktake variance ($)</span>
                    <input
                        v-model.number="settings.large_variance"
                        type="number"
                        min="0"
                        step="1"
                        class="h-9 w-28 rounded-md border bg-background px-2"
                    />
                </label>
                <button type="submit" class="h-9 rounded-md border px-3" :disabled="settings.processing">Save</button>
                <span class="text-red-700">{{ settings.errors.end_hour ?? settings.errors.start_hour ?? settings.errors.large_variance }}</span>
            </form>
            <InsightFilterBar :filters="filters" :options="options" index-route="reports.exceptions.index" export-route="reports.exceptions.export" />
            <p class="text-xs text-muted-foreground">
                Patterns worth a second look, not proof of anything. Working hours {{ report.work_hours }}; outside them or at the weekend is flagged.
                Click a figure to see the movements or pick lists behind it.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Adjustments out of hours"
                    :value="num(t.out_of_hours)"
                    :sub="`of ${num(t.adjustments)} adjustments and write-offs`"
                    :tone="t.out_of_hours ? 'warn' : null"
                />
                <StatTile
                    label="Repeated losses"
                    :value="`${num(t.repeated_skus)} SKUs · ${num(t.repeated_bins)} bins`"
                    sub="2 or more unexplained losses"
                    :tone="t.repeated_skus || t.repeated_bins ? 'warn' : null"
                />
                <StatTile
                    label="Short / unscanned picks"
                    :value="`${num(t.short_picks)} / ${num(t.unscanned_picks)}`"
                    :sub="`${num(t.negative_bins)} bins went negative`"
                    :tone="t.negative_bins ? 'warn' : null"
                />
                <StatTile
                    label="Self-counted big variances"
                    :value="num(t.self_counted)"
                    :sub="`counted and posted by one person, $${report.large_variance}+`"
                    :tone="t.self_counted ? 'warn' : null"
                />
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Adjustments and write-offs by user</h2>
                <DataTable :columns="userColumns" :rows="report.by_user" empty="No adjustments or write-offs in these dates." />
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">By reason</h2>
                    <DataTable :columns="reasonColumns" :rows="report.by_reason" empty="None." />
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">By hour of day</h2>
                    <BarTrend
                        :points="hourPoints"
                        title="Adjustments and write-offs by hour of day"
                        :format="(n) => `${n} adjustment${n === 1 ? '' : 's'}`"
                    />
                </section>
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">After hours or at the weekend</h2>
                <DataTable :columns="outColumns" :rows="report.out_of_hours" empty="Nothing out of hours." />
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">Repeated losses: same SKU</h2>
                    <DataTable :columns="repeatSku" :rows="report.repeated.skus" empty="No SKU lost stock twice." />
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">Repeated losses: same bin</h2>
                    <DataTable :columns="repeatBin" :rows="report.repeated.bins" empty="No bin lost stock twice." />
                </section>
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Bins that went negative</h2>
                <DataTable :columns="negativeColumns" :rows="report.negative_bins" empty="No bin went negative." />
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">Short picks by picker and SKU</h2>
                    <DataTable :columns="shortColumns" :rows="asText(report.short_picks, ['pick_lists'])" empty="No short picks." />
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">Picks entered without scanning</h2>
                    <DataTable
                        :columns="unscannedColumns"
                        :rows="asText(report.unscanned_picks, ['pick_lists', 'skus'])"
                        empty="Every pick was scanned."
                    />
                </section>
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Counted and posted by the same person, with a large variance</h2>
                <DataTable :columns="selfColumns" :rows="report.self_counted" empty="None." />
            </section>
        </div>
    </AppLayout>
</template>
