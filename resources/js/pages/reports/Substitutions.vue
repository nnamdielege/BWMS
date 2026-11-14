<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Row {
    id: number;
    date: string;
    order_id: number;
    order: string;
    channel: string;
    customer: string | null;
    original_sku: string;
    original_name: string;
    original_qty: string;
    original_price: number | null;
    substitute_sku: string;
    substitute_name: string;
    substitute_qty: string;
    substitute_price: number | null;
    price_difference: number | null;
    kept_original_price: boolean;
    note: string;
    by: string | null;
}

const props = defineProps<{
    filters: { from: string; to: string };
    report: { rows: Row[]; totals: { count: number; price_difference: number } };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Substitutions', href: '/reports/substitutions' }];
const filter = reactive({ ...props.filters });
const reload = () => router.get(route('reports.substitutions.index'), { ...filter }, { preserveState: true, preserveScroll: true, replace: true });
const exportUrl = (format: 'xlsx' | 'csv') => route('reports.substitutions.export', { format, ...filter });
const money = (n: number | null) => (n === null ? '—' : `$${n.toFixed(2)}`);
const signed = (n: number | null) => (n === null ? '—' : `${n < 0 ? '−' : n > 0 ? '+' : ''}$${Math.abs(n).toFixed(2)}`);
</script>

<template>
    <Head title="Substitutions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-end gap-3">
                <h1 class="mr-auto text-xl font-semibold">Substitutions</h1>
                <label class="text-sm">
                    <span class="block text-muted-foreground">From</span>
                    <input v-model="filter.from" type="date" class="h-9 rounded-md border bg-background px-2" @change="reload" />
                </label>
                <label class="text-sm">
                    <span class="block text-muted-foreground">To</span>
                    <input v-model="filter.to" type="date" class="h-9 rounded-md border bg-background px-2" @change="reload" />
                </label>
                <div class="text-sm">Export: <a :href="exportUrl('xlsx')" class="underline">XLSX</a> <a :href="exportUrl('csv')" class="underline">CSV</a></div>
            </header>
            <p class="text-xs text-muted-foreground">
                What customers accepted instead of what they ordered, by the day it was agreed. Money ex GST; price difference = substitute value − original value. Undone substitutions are left out.
            </p>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-2">Date</th><th>Order</th><th>Customer</th><th>Original</th><th>Substitute</th>
                            <th class="text-right">Price difference</th><th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in report.rows" :key="r.id" class="border-b align-top">
                            <td class="whitespace-nowrap py-2 tabular-nums">{{ r.date }}</td>
                            <td><Link :href="route('orders.show', r.order_id)" class="font-mono underline">{{ r.order }}</Link></td>
                            <td>{{ r.customer ?? '—' }}</td>
                            <td>
                                <span class="font-mono">{{ r.original_sku }}</span> {{ r.original_name }}
                                <div class="text-xs text-muted-foreground">{{ r.original_qty }} at {{ money(r.original_price) }}</div>
                            </td>
                            <td>
                                <span class="font-mono">{{ r.substitute_sku }}</span> {{ r.substitute_name }}
                                <div class="text-xs text-muted-foreground">{{ r.substitute_qty }} at {{ money(r.substitute_price) }}<template v-if="r.kept_original_price"> (original price kept)</template></div>
                            </td>
                            <td class="text-right tabular-nums">{{ signed(r.price_difference) }}</td>
                            <td class="text-xs">{{ r.note }}<div class="text-muted-foreground">{{ r.by }}</div></td>
                        </tr>
                        <tr v-if="!report.rows.length">
                            <td colspan="7" class="py-6 text-center text-muted-foreground">No substitutions in these dates.</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="report.rows.length">
                        <tr class="font-semibold">
                            <td class="py-2" colspan="5">{{ report.totals.count }} substitution{{ report.totals.count === 1 ? '' : 's' }}</td>
                            <td class="text-right tabular-nums">{{ signed(report.totals.price_difference) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
