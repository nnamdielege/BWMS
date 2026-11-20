<script setup lang="ts">
import DataTable from '@/components/reports/DataTable.vue';
import InsightFilterBar from '@/components/reports/InsightFilterBar.vue';
import StatTile from '@/components/reports/StatTile.vue';
import type { Column, InsightFilters, InsightOptions, Row } from '@/components/reports/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, num } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type GroupBy = 'sku' | 'supplier' | 'po';
const props = defineProps<{
    filters: InsightFilters;
    options: InsightOptions;
    group: GroupBy;
    report: {
        lines: Row[];
        totals: Record<string, number>;
        groups: Record<GroupBy, Row[]>;
        suppliers: Row[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stock received', href: '/reports/received' }];
const group = ref<GroupBy>(props.group);
const t = computed(() => props.report.totals);
const labels: Record<GroupBy, string> = { sku: 'SKU', supplier: 'Supplier', po: 'PO' };
const showLines = ref(false);

const groupColumns = computed<Column[]>(() => [
    {
        key: 'label',
        label: labels[group.value],
        href: (r) =>
            group.value === 'po' ? route('purchase-orders.show', r.po_id) : group.value === 'sku' ? route('stock.show', r.product_id) : null,
        sub: (r) => (group.value === 'sku' && r.pack ? `${r.packs} × ${r.pack}` : null),
    },
    { key: 'units', label: 'Units', kind: 'int' },
    { key: 'cost', label: 'Cost', kind: 'money' },
    { key: 'receipts', label: 'Receipts', kind: 'int' },
    { key: 'damaged', label: 'Damaged', kind: 'int', cellClass: (r) => (r.damaged ? 'text-amber-700 dark:text-amber-400' : '') },
    { key: 'rejected', label: 'Rejected', kind: 'int', cellClass: (r) => (r.rejected ? 'text-amber-700 dark:text-amber-400' : '') },
    { key: 'over', label: 'Over', kind: 'int' },
    { key: 'short', label: 'Short', kind: 'int', cellClass: (r) => (r.short ? 'text-red-700 dark:text-red-400' : '') },
    { key: 'outstanding', label: 'Outstanding', kind: 'int' },
]);
const supplierColumns: Column[] = [
    { key: 'supplier', label: 'Supplier', sub: (r) => r.po_numbers.join(', ') },
    { key: 'pos', label: 'POs', kind: 'int' },
    { key: 'on_time_percent', label: 'On time', kind: 'pct', sub: (r) => (r.dated ? `${r.on_time} of ${r.dated}` : 'no expected dates') },
    { key: 'avg_days_late', label: 'Avg days late' },
    { key: 'lead_actual', label: 'Lead time (days)', sub: (r) => (r.lead_set === null ? 'none set' : `set: ${r.lead_set}`) },
    { key: 'fill_percent', label: 'Fill rate', kind: 'pct', sub: (r) => `${r.finished} finished PO${r.finished === 1 ? '' : 's'}` },
];
const lineColumns: Column[] = [
    { key: 'date', label: 'Date' },
    { key: 'receipt', label: 'Receipt', sub: (r) => r.po_number },
    { key: 'supplier', label: 'Supplier' },
    { key: 'sku', label: 'SKU', sub: (r) => r.name },
    { key: 'packs', label: 'Packs', sub: (r) => r.pack },
    { key: 'accepted', label: 'Accepted', kind: 'int' },
    { key: 'damaged', label: 'Damaged', kind: 'int' },
    { key: 'rejected', label: 'Rejected', kind: 'int', sub: (r) => r.discrepancy_reason },
    { key: 'cost', label: 'Cost', kind: 'money' },
];
</script>

<template>
    <Head title="Stock received" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4">
            <h1 class="text-xl font-semibold">Stock received</h1>
            <InsightFilterBar
                :filters="filters"
                :options="options"
                index-route="reports.received.index"
                export-route="reports.received.export"
                :extra="{ group }"
                supplier-label="Supplier (PO)"
            />
            <p class="text-xs text-muted-foreground">
                Receipts in these dates at the PO cost, ex GST. Units are base units. Over and short are against each PO line over its life; short
                counts once the PO is finished, until then the rest is outstanding.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Received at cost" :value="money(t.cost)" :sub="`${num(t.receipts)} receipts · ${num(t.lines)} lines`" />
                <StatTile label="Units received" :value="num(t.units)" sub="base units, damaged included" />
                <StatTile
                    label="Damaged / rejected"
                    :value="`${num(t.damaged)} / ${num(t.rejected)}`"
                    :sub="`damaged worth ${money(t.damaged_cost)}`"
                    :tone="t.damaged || t.rejected ? 'warn' : null"
                />
                <StatTile label="Short / over" :value="`${num(t.short)} / ${num(t.over)}`" :sub="`${num(t.outstanding)} still outstanding`" />
            </div>

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
                <DataTable :columns="groupColumns" :rows="report.groups[group]" empty="Nothing received in these dates." />
            </section>

            <section class="rounded-lg border p-4">
                <h2 class="font-semibold">Supplier performance</h2>
                <p class="mb-2 text-xs text-muted-foreground">
                    POs first received in these dates. On time: first delivery on or before the expected date. Lead time: sent (or ordered) to first
                    delivery. Fill rate: good stock received ÷ ordered, finished POs only.
                </p>
                <DataTable :columns="supplierColumns" :rows="report.suppliers" empty="No POs first received in these dates." />
            </section>

            <section>
                <button type="button" class="text-sm underline" @click="showLines = !showLines">
                    {{ showLines ? 'Hide' : 'Show' }} every receipt line ({{ report.lines.length }})
                </button>
                <DataTable v-if="showLines" class="mt-2" :columns="lineColumns" :rows="report.lines" :limit="200" />
            </section>
        </div>
    </AppLayout>
</template>
