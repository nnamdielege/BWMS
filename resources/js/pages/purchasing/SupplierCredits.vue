<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';

interface Credit {
    id: number;
    date: string;
    age_days: number;
    supplier: string;
    sku: string | null;
    name: string | null;
    qty: number;
    base_unit: string | null;
    batch: string | null;
    amount: number;
    no_cost: boolean;
    status: 'open' | 'received';
    note: string | null;
    credit_ref: string | null;
    by: string | null;
    received_at: string | null;
    received_by: string | null;
}

defineProps<{
    status: 'open' | 'received' | 'all';
    rows: Credit[];
    by_supplier: { supplier: string; count: number; amount: number; oldest_days: number }[];
    total: number;
    can: { manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Supplier credits', href: '/purchasing/supplier-credits' }];
const money = (n: number) => `$${n.toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const show = (status: string) => router.get(route('supplier-credits.index'), { status }, { preserveScroll: true, replace: true });
const receive = (c: Credit) => {
    const ref = window.prompt(`Credit from ${c.supplier} received. Their credit note number (optional):`);
    if (ref === null) return;
    router.post(route('supplier-credits.receive', c.id), { credit_ref: ref || null }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Supplier credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="mr-auto text-xl font-semibold">Supplier credits</h1>
                <button
                    v-for="s in ['open', 'received', 'all']"
                    :key="s"
                    type="button"
                    class="rounded-md border px-2 py-0.5 text-sm capitalize"
                    :class="status === s ? 'bg-muted font-medium' : ''"
                    @click="show(s)"
                >
                    {{ s }}
                </button>
            </header>
            <p class="text-xs text-muted-foreground">
                Stock returned to suppliers from quarantine, and the credit expected for it: the quantity at the average cost when it went back, ex GST.
            </p>

            <section v-if="by_supplier.length" class="max-w-xl rounded-lg border p-4">
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="py-1">Supplier</th><th class="text-right">Credits</th><th class="text-right">Oldest (days)</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in by_supplier" :key="s.supplier" class="border-b">
                            <td class="py-1">{{ s.supplier }}</td>
                            <td class="text-right tabular-nums">{{ s.count }}</td>
                            <td class="text-right tabular-nums">{{ s.oldest_days }}</td>
                            <td class="text-right tabular-nums">{{ money(s.amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-1" colspan="3">Total {{ status === 'open' ? 'owed' : '' }}</td>
                            <td class="text-right tabular-nums">{{ money(total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-2">Returned</th><th>Supplier</th><th>Product</th><th class="text-right">Qty</th>
                            <th class="text-right">Credit</th><th>Note</th><th>Status</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in rows" :key="c.id" class="border-b align-top">
                            <td class="whitespace-nowrap py-2 tabular-nums">{{ c.date }}<div class="text-xs text-muted-foreground">{{ c.age_days }} days ago</div></td>
                            <td>{{ c.supplier }}</td>
                            <td><span class="font-mono">{{ c.sku }}</span> {{ c.name }}<div v-if="c.batch" class="text-xs text-muted-foreground">Batch {{ c.batch }}</div></td>
                            <td class="text-right tabular-nums">{{ c.qty }} {{ c.base_unit }}</td>
                            <td class="text-right tabular-nums">
                                {{ money(c.amount) }}
                                <div v-if="c.no_cost" class="text-xs text-red-700">no cost when returned</div>
                            </td>
                            <td class="text-xs">{{ c.note }}<div class="text-muted-foreground">{{ c.by }}</div></td>
                            <td class="text-xs">
                                <span v-if="c.status === 'open'" class="rounded bg-amber-100 px-1 text-amber-900">open</span>
                                <template v-else>
                                    received {{ c.received_at }}<div class="text-muted-foreground">{{ c.credit_ref }} {{ c.received_by }}</div>
                                </template>
                            </td>
                            <td class="text-right"><Button v-if="can.manage && c.status === 'open'" size="sm" variant="ghost" @click="receive(c)">Mark received</Button></td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="8" class="py-6 text-center text-muted-foreground">No {{ status === 'all' ? '' : status }} supplier credits.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
