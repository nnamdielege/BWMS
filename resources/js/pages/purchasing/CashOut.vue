<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface Week {
    start: string;
    end: string;
    payments: number;
    payments_ex: number;
    inflow: number;
    inflow_ex: number;
    net: number;
    net_ex: number;
}
interface Report {
    weeks: Week[];
    suppliers: { id: number; name: string; amounts: number[]; total: number; total_ex: number }[];
    overdue: { count: number; total: number; total_ex: number };
    inflow_per_week: number;
    inflow_per_week_ex: number;
    unpriced_products: number;
}

defineProps<{ report: Report }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchase-orders' },
    { title: 'Cash out', href: '/purchasing/cash-out' },
];
const money = (v: number) => v.toLocaleString('en-AU', { style: 'currency', currency: 'AUD', maximumFractionDigits: 0 });
const cell = (v: number) => (v ? money(v) : '');
const day = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short' });
const sum = (weeks: Week[], key: keyof Week) => weeks.reduce((t, w) => t + (w[key] as number), 0);

// Footer: each figure inc GST (bold, "Total" column) then its ex-GST line (muted, "Total ex GST" column).
const totals: { label: string; inc: keyof Week; ex: keyof Week; net?: boolean; note?: string }[] = [
    { label: 'Supplier payments', inc: 'payments', ex: 'payments_ex' },
    { label: 'Inflow', inc: 'inflow', ex: 'inflow_ex', note: 'indicative' },
    { label: 'Net', inc: 'net', ex: 'net_ex', net: true },
];
const tone = (v: number, net?: boolean) => (net ? (v < 0 ? 'text-red-600' : 'text-green-700 dark:text-green-400') : '');
</script>

<template>
    <Head title="Cash out" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Cash out</h1>
                <p class="text-sm text-muted-foreground">
                    Supplier payments due each week (approved, sent, received and closed POs not yet marked paid, by payment due date) against an
                    indicative inflow from current sales. Inc GST, with ex GST beneath. Fixed costs stay in Xero and aren't included.
                </p>
            </div>

            <p
                v-if="report.overdue.count"
                class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
            >
                {{ report.overdue.count }} PO{{ report.overdue.count === 1 ? ' is' : 's are' }} past the payment due date and not marked paid:
                <span class="font-semibold">{{ money(report.overdue.total) }}</span> inc GST ({{ money(report.overdue.total_ex) }} ex).
                <Link :href="route('purchase-orders.index', { status: 'all' })" class="underline">Purchase orders</Link>
            </p>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="sticky left-0 bg-background py-2 pr-3 text-left">Week of</th>
                            <th v-for="w in report.weeks" :key="w.start" class="whitespace-nowrap px-2" :title="`${w.start} to ${w.end}`">
                                {{ day(w.start) }}
                            </th>
                            <th class="pl-3">Total</th>
                            <th class="pl-3">Total ex GST</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in report.suppliers" :key="s.id" class="border-b">
                            <td class="sticky left-0 bg-background py-1.5 pr-3 text-left">{{ s.name }}</td>
                            <td v-for="(a, i) in s.amounts" :key="i" class="px-2 tabular-nums">{{ cell(a) }}</td>
                            <td class="pl-3 tabular-nums">{{ money(s.total) }}</td>
                            <td class="pl-3 tabular-nums text-muted-foreground">{{ money(s.total_ex) }}</td>
                        </tr>
                        <tr v-if="!report.suppliers.length">
                            <td :colspan="report.weeks.length + 3" class="py-4 text-center text-muted-foreground">
                                No unpaid POs with a payment due in the next 13 weeks.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <template v-for="t in totals" :key="t.label">
                            <tr class="font-semibold">
                                <td class="sticky left-0 bg-background pr-3 pt-2 text-left">
                                    {{ t.label }} <span class="text-xs font-normal">inc GST</span>
                                    <span v-if="t.note" class="text-xs font-normal italic text-muted-foreground"> ({{ t.note }})</span>
                                </td>
                                <td v-for="w in report.weeks" :key="w.start" class="px-2 pt-2 tabular-nums" :class="tone(w[t.inc] as number, t.net)">
                                    {{ money(w[t.inc] as number) }}
                                </td>
                                <td class="pl-3 pt-2 tabular-nums" :class="tone(sum(report.weeks, t.inc), t.net)">
                                    {{ money(sum(report.weeks, t.inc)) }}
                                </td>
                                <td></td>
                            </tr>
                            <tr class="border-b text-muted-foreground">
                                <td class="sticky left-0 bg-background pb-2 pr-3 text-left text-xs">ex GST</td>
                                <td v-for="w in report.weeks" :key="w.start" class="px-2 pb-2 tabular-nums">{{ money(w[t.ex] as number) }}</td>
                                <td></td>
                                <td class="pb-2 pl-3 tabular-nums">{{ money(sum(report.weeks, t.ex)) }}</td>
                            </tr>
                        </template>
                    </tfoot>
                </table>
            </div>

            <p class="text-xs text-muted-foreground">
                GST on payments is each PO line's own tax rate (0% lines carry none). Indicative inflow: each active product's average daily sales
                (the 8-week weighted figure the reorder suggestions use) × 7 × its default sell price, plus 10% GST unless the product is GST-free:
                {{ money(report.inflow_per_week) }} a week inc GST ({{ money(report.inflow_per_week_ex) }} ex), the same every week. It's a guide, not
                a forecast.
                <span v-if="report.unpriced_products">{{ report.unpriced_products }} selling product(s) have no sell price and aren't counted.</span>
                A PO's payment due date is set when it's approved: expected delivery + the supplier's payment terms.
            </p>
        </div>
    </AppLayout>
</template>
