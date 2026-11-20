<script setup lang="ts">
import DataTable from '@/components/reports/DataTable.vue';
import InsightFilterBar from '@/components/reports/InsightFilterBar.vue';
import StatTile from '@/components/reports/StatTile.vue';
import type { Column, InsightFilters, InsightOptions, Row } from '@/components/reports/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, num, pct } from '@/lib/money';
import { movementsHref } from '@/lib/movements';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type GroupBy = 'reason' | 'recipient' | 'product' | 'person';
const props = defineProps<{
    filters: InsightFilters;
    options: InsightOptions;
    group: GroupBy;
    report: {
        rows: Row[];
        totals: Record<string, number | null>;
        groups: Record<GroupBy, Row[]>;
        samples: Row[];
        budget: { monthly: number | null; months: { month: string; label: string; spent: number; budget: number | null; over: boolean }[] };
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Samples, gifts & favours', href: '/reports/giveaways' }];
const group = ref<GroupBy>(props.group);
const t = computed(() => props.report.totals);
const labels: Record<GroupBy, string> = { reason: 'Reason', recipient: 'Recipient', product: 'Product', person: 'Entered by' };
const showAll = ref(false);
const outcome = (v: string) =>
    v === 'yes' ? 'text-green-700 dark:text-green-400' : v === 'no' ? 'text-muted-foreground' : 'italic text-muted-foreground';

const groupColumns = computed<Column[]>(() => [
    { key: 'label', label: labels[group.value], href: (r) => (r.product_id ? route('stock.show', r.product_id) : null) },
    { key: 'count', label: 'Times', kind: 'int' },
    { key: 'units', label: 'Units', kind: 'int' },
    { key: 'value', label: 'Cost', kind: 'money' },
    { key: 'samples', label: 'Samples to customers', kind: 'int' },
    { key: 'conversion_30', label: 'Ordered in 30 days', kind: 'pct', sub: (r) => (r.decided_30 ? `${r.converted_30} of ${r.decided_30}` : null) },
    { key: 'conversion_60', label: 'in 60 days', kind: 'pct', sub: (r) => (r.decided_60 ? `${r.converted_60} of ${r.decided_60}` : null) },
]);
const sampleColumns: Column[] = [
    { key: 'date', label: 'Sampled' },
    { key: 'customer', label: 'Customer' },
    { key: 'sku', label: 'Product', sub: (r) => r.name, href: (r) => route('stock.show', r.product_id) },
    { key: 'value', label: 'Cost', kind: 'money' },
    { key: 'first_order', label: 'First order after', sub: (r) => (r.days_to_order === null ? null : `${r.days_to_order} days later`) },
    { key: 'within_30', label: '30 days', cellClass: (r) => outcome(r.within_30) },
    { key: 'within_60', label: '60 days', cellClass: (r) => outcome(r.within_60) },
];
const rowColumns: Column[] = [
    { key: 'date', label: 'Date' },
    { key: 'reason', label: 'Reason' },
    { key: 'recipient', label: 'To', sub: (r) => r.purpose },
    { key: 'sku', label: 'Product', sub: (r) => r.name },
    { key: 'units', label: 'Units', kind: 'int', sub: (r) => (r.pack ? `${r.packs} × ${r.pack}` : null) },
    { key: 'value', label: 'Cost', kind: 'money', href: (r) => movementsHref([r.stock_movement_id]) },
    { key: 'approved_by', label: 'Approved by', sub: (r) => `entered by ${r.person}` },
];
const budgetMax = computed(() => Math.max(1, props.report.budget.monthly ?? 0, ...props.report.budget.months.map((m) => m.spent)));
</script>

<template>
    <Head title="Samples, gifts & favours" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="mr-auto text-xl font-semibold">Samples, gifts &amp; favours</h1>
                <Link :href="route('giveaways.index')" class="text-sm underline">Record a give-away</Link>
            </div>
            <InsightFilterBar
                :filters="filters"
                :options="options"
                index-route="reports.giveaways.index"
                export-route="reports.giveaways.export"
                :extra="{ group }"
            />
            <p class="text-xs text-muted-foreground">
                Stock given away in these dates, at cost. A sample to a customer converted when they ordered that product within 30 / 60 days; samples
                still inside the window with no order are pending and left out of the %.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Given away at cost" :value="money(t.value)" :sub="`${num(t.count)} times · ${num(t.units)} units`" />
                <StatTile label="Samples to customers" :value="num(t.samples_to_customers)" />
                <StatTile
                    label="Ordered within 30 days"
                    :value="pct(t.conversion_30)"
                    :sub="t.decided_30 ? `${t.converted_30} of ${t.decided_30} samples` : 'none decided yet'"
                />
                <StatTile
                    label="Ordered within 60 days"
                    :value="pct(t.conversion_60)"
                    :sub="t.decided_60 ? `${t.converted_60} of ${t.decided_60} samples` : 'none decided yet'"
                />
            </div>

            <section class="rounded-lg border p-4">
                <h2 class="font-semibold">Spend against budget</h2>
                <p v-if="report.budget.monthly === null" class="text-sm text-muted-foreground">
                    No monthly budget. Set one on <Link :href="route('giveaways.index')" class="underline">Stock → Give away</Link>.
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="m in report.budget.months" :key="m.month" class="flex items-center gap-2">
                        <span class="w-20">{{ m.label }}</span>
                        <span class="relative h-3 flex-1 rounded-sm bg-muted">
                            <span
                                class="block h-3 rounded-sm"
                                :class="m.over ? 'bg-red-600' : 'bg-sky-600 dark:bg-sky-400'"
                                :style="{ width: `${(m.spent / budgetMax) * 100}%` }"
                            ></span>
                            <span
                                v-if="m.budget !== null"
                                class="absolute top-[-2px] h-4 w-0.5 bg-foreground"
                                :style="{ left: `${(m.budget / budgetMax) * 100}%` }"
                                :title="`Budget ${money(m.budget)}`"
                            ></span>
                        </span>
                        <span class="w-40 text-right tabular-nums">
                            {{ money(m.spent) }}<template v-if="m.budget !== null"> / {{ money(m.budget) }}</template>
                        </span>
                    </li>
                </ul>
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
                <DataTable :columns="groupColumns" :rows="report.groups[group]" empty="Nothing given away in these dates." />
            </section>

            <section class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Samples to customers: did they order?</h2>
                <DataTable :columns="sampleColumns" :rows="report.samples" empty="No samples to customers in these dates." />
            </section>

            <section>
                <button type="button" class="text-sm underline" @click="showAll = !showAll">
                    {{ showAll ? 'Hide' : 'Show' }} every give-away ({{ report.rows.length }})
                </button>
                <DataTable v-if="showAll" class="mt-2" :columns="rowColumns" :rows="report.rows" :limit="200" />
            </section>
        </div>
    </AppLayout>
</template>
