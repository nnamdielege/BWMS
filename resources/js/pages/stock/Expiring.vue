<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Row {
    product_id: number;
    sku: string;
    name: string;
    base_unit: string;
    supplier: string | null;
    batch_id: number;
    batch: string;
    best_before: string;
    days_left: number;
    on_hand: number;
    ahead: number;
    daily_rate: number;
    expected_sold: number;
    left_over: number;
    status: 'expired' | 'at_risk' | 'ok';
    unit_cost: number | null;
    value_left: number | null;
}

const props = defineProps<{
    report: { days: number; rows: Row[]; totals: { batches: number; at_risk: number; expired: number; units_left: number; value_left: number | null } };
    settings: { days: number; in_email: boolean };
    can: { settings: boolean; costs: boolean; move: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock on hand', href: '/stock' },
    { title: 'Expiring soon', href: '/stock/expiring' },
];
const money = (n: number | null) => (n === null ? '—' : `$${n.toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
const settingsForm = useForm({ ...props.settings });
const saveSettings = () => settingsForm.put(route('expiring.settings'), { preserveScroll: true });
const daysLabel = (d: number) => (d < 0 ? `${-d} day${d === -1 ? '' : 's'} ago` : d === 0 ? 'today' : `in ${d} day${d === 1 ? '' : 's'}`);
</script>

<template>
    <Head title="Expiring soon" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-baseline gap-4">
                <h1 class="mr-auto text-xl font-semibold">Expiring soon</h1>
                <span class="text-sm">{{ report.totals.batches }} batch{{ report.totals.batches === 1 ? '' : 'es' }} within {{ report.days }} days</span>
                <span class="text-sm">Won't sell in time <strong class="tabular-nums">{{ report.totals.at_risk }}</strong></span>
                <span v-if="report.totals.expired" class="text-sm text-red-700">Already past <strong class="tabular-nums">{{ report.totals.expired }}</strong></span>
                <span v-if="can.costs" class="text-sm">At risk, at cost <strong class="tabular-nums">{{ money(report.totals.value_left) }}</strong></span>
            </header>
            <p class="text-xs text-muted-foreground">
                Batches in sellable bins with a best-before in the next {{ report.days }} days, or already past it. Picking is first-expiry-first, so a batch
                only starts selling once the stock ahead of it is gone. Expected to sell = daily sales (8-week average, as on reorder suggestions) × days left
                including the best-before day, less the stock ahead. Past best-before stock can't be sold: move it to quarantine.
            </p>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-2">Product</th><th>Batch</th><th>Best before</th><th class="text-right">On hand</th>
                            <th class="text-right" title="Sellable stock picked before this batch">Ahead</th><th class="text-right">Sells / day</th>
                            <th class="text-right">Expected to sell</th><th class="text-right">Won't sell</th><th v-if="can.costs" class="text-right">At cost</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in report.rows" :key="r.batch_id" class="border-b align-top">
                            <td class="py-2"><span class="font-mono">{{ r.sku }}</span> {{ r.name }}<div v-if="r.supplier" class="text-xs text-muted-foreground">{{ r.supplier }}</div></td>
                            <td>{{ r.batch }}</td>
                            <td class="whitespace-nowrap tabular-nums">{{ r.best_before }}<div class="text-xs text-muted-foreground">{{ daysLabel(r.days_left) }}</div></td>
                            <td class="text-right tabular-nums">{{ r.on_hand }} {{ r.base_unit }}</td>
                            <td class="text-right tabular-nums">{{ r.ahead }}</td>
                            <td class="text-right tabular-nums">{{ r.daily_rate }}</td>
                            <td class="text-right tabular-nums">{{ r.expected_sold }}</td>
                            <td class="text-right tabular-nums">
                                <span v-if="r.status === 'expired'" class="rounded bg-red-100 px-1 text-xs text-red-800">past best-before</span>
                                <span v-else-if="r.status === 'at_risk'" class="rounded bg-amber-100 px-1 text-amber-900">{{ r.left_over }}</span>
                                <span v-else class="text-xs text-muted-foreground">sells in time</span>
                            </td>
                            <td v-if="can.costs" class="text-right tabular-nums">{{ r.left_over ? money(r.value_left) : '' }}</td>
                            <td class="text-right"><Link v-if="can.move" :href="route('stock.show', r.product_id)" class="text-sm underline">Stock…</Link></td>
                        </tr>
                        <tr v-if="!report.rows.length">
                            <td colspan="10" class="py-6 text-center text-muted-foreground">Nothing expires in the next {{ report.days }} days.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form v-if="can.settings" class="flex flex-wrap items-end gap-3 rounded-lg border p-4 text-sm" @submit.prevent="saveSettings">
                <label>
                    <span class="block text-muted-foreground">Expiring within (days)</span>
                    <input v-model.number="settingsForm.days" type="number" min="1" max="365" class="h-9 w-24 rounded-md border bg-background px-2" />
                </label>
                <label class="flex h-9 items-center gap-2"><input v-model="settingsForm.in_email" type="checkbox" /> List batches that won't sell in time in the 7am email</label>
                <Button type="submit" size="sm" :disabled="settingsForm.processing">Save</Button>
                <InputError :message="settingsForm.errors.days" />
            </form>
        </div>
    </AppLayout>
</template>
