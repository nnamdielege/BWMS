<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface Row {
    product_id: number;
    sku: string;
    name: string;
    base_unit: string;
    supplier: string | null;
    is_active: boolean;
    on_hand: number;
    velocity: number;
    avg_cost: number;
    value: number;
    days_cover: number | null;
}

defineProps<{ report: { rows: Row[]; total_value: number }; threshold: number }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchase-orders' },
    { title: 'Slow movers', href: '/purchasing/slow-movers' },
];
const money = (v: number) => v.toLocaleString('en-AU', { style: 'currency', currency: 'AUD' });
</script>

<template>
    <Head title="Slow movers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h1 class="text-xl font-semibold">Slow movers</h1>
                    <p class="text-sm text-muted-foreground">
                        Stock on hand selling under {{ threshold }} a day (the 8-week weighted figure the reorder suggestions use), by value at
                        average cost.
                    </p>
                </div>
                <div class="text-sm">
                    Tied up: <span class="font-semibold tabular-nums">{{ money(report.total_value) }}</span>
                    <span class="text-muted-foreground">ex GST</span>
                </div>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Product</th>
                        <th>Supplier</th>
                        <th class="text-right">On hand</th>
                        <th class="text-right">Sold / day</th>
                        <th class="text-right">Avg cost</th>
                        <th class="text-right">Value at cost</th>
                        <th class="text-right">Days of cover</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in report.rows" :key="r.product_id" class="border-b">
                        <td class="py-2">
                            <Link :href="route('stock.show', r.product_id)" class="font-mono underline">{{ r.sku }}</Link> {{ r.name }}
                            <span v-if="!r.is_active" class="ml-1 text-xs text-muted-foreground">inactive</span>
                        </td>
                        <td>{{ r.supplier ?? '—' }}</td>
                        <td class="text-right tabular-nums">
                            {{ r.on_hand }} <span class="text-xs text-muted-foreground">{{ r.base_unit }}</span>
                        </td>
                        <td class="text-right tabular-nums">{{ r.velocity.toFixed(3) }}</td>
                        <td class="text-right tabular-nums">{{ money(r.avg_cost) }}</td>
                        <td class="text-right font-medium tabular-nums">{{ money(r.value) }}</td>
                        <td class="text-right tabular-nums">{{ r.days_cover === null ? 'no sales' : r.days_cover.toLocaleString('en-AU') }}</td>
                    </tr>
                    <tr v-if="!report.rows.length">
                        <td colspan="7" class="py-6 text-center text-muted-foreground">No slow movers: everything in stock is selling.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
