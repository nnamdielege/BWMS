<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface PoLine {
    id: number;
    product: { id: number; sku: string; name: string; base_unit: string; is_batch_tracked: boolean };
    unit: string;
    base_qty_per_unit: number;
    qty_ordered_base: number;
    outstanding_base: number;
}
type Row = {
    purchase_order_line_id: number;
    packs: number | null;
    loose: number | null;
    damaged: number | null;
    rejected: number | null;
    location_id: number | null;
    lot_code: string;
    best_before: string;
    discrepancy_reason: string | null;
    note: string;
};

const props = defineProps<{
    po: { id: number; number: string; supplier: string; site: string; lines: PoLine[] };
    bins: { id: number; label: string; receiving: boolean }[];
    reasons: string[];
    clientToken: string;
    canOverReceive: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchase orders', href: '/purchase-orders' },
    { title: props.po.number, href: route('purchase-orders.show', props.po.id) },
    { title: 'Receive', href: '#' },
];
const defaultBin = props.bins.find((b) => b.receiving)?.id ?? props.bins[0]?.id ?? null;
const blank = (l: PoLine): Row => ({
    purchase_order_line_id: l.id, packs: null, loose: null, damaged: null, rejected: null,
    location_id: defaultBin, lot_code: '', best_before: '', discrepancy_reason: null, note: '',
});

const form = useForm({
    client_token: props.clientToken,
    supplier_ref: '',
    notes: '',
    allow_over_receipt: false,
    rows: props.po.lines.map(blank),
});

const lineOf = (r: Row) => props.po.lines.find((l) => l.id === r.purchase_order_line_id)!;
const acceptedBase = (r: Row) => (r.packs ?? 0) * lineOf(r).base_qty_per_unit + (r.loose ?? 0);
const incomingFor = (lineId: number) =>
    form.rows.filter((r) => r.purchase_order_line_id === lineId).reduce((s, r) => s + acceptedBase(r) + (r.damaged ?? 0), 0);
const isOver = (lineId: number) => incomingFor(lineId) > lineOf(form.rows.find((r) => r.purchase_order_line_id === lineId)!).outstanding_base;
const anyOver = computed(() => props.po.lines.some((l) => incomingFor(l.id) > l.outstanding_base));
// Batch-tracked lines can be split into several lots.
const splitRow = (i: number) => form.rows.splice(i + 1, 0, blank(lineOf(form.rows[i])));
const receiveAllOutstanding = () =>
    form.rows.forEach((r) => {
        const l = lineOf(r);
        r.packs = Math.floor(l.outstanding_base / l.base_qty_per_unit);
        r.loose = l.outstanding_base % l.base_qty_per_unit || null;
    });

const errors = computed(() => usePage().props.errors as Record<string, string>);
const submit = () =>
    form
        .transform((d) => ({
            client_token: d.client_token,
            supplier_ref: d.supplier_ref,
            notes: d.notes,
            allow_over_receipt: d.allow_over_receipt,
            lines: d.rows.map((r) => ({
                purchase_order_line_id: r.purchase_order_line_id,
                qty_accepted_base: acceptedBase(r),
                qty_damaged_base: r.damaged ?? 0,
                qty_rejected_base: r.rejected ?? 0,
                location_id: r.location_id,
                lot_code: r.lot_code || null,
                best_before: r.best_before || null,
                discrepancy_reason: r.discrepancy_reason,
                note: r.note || null,
            })),
        }))
        .post(route('purchase-orders.receipts.store', props.po.id));
</script>

<template>
    <Head :title="`Receive ${po.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="space-y-4 p-4" @submit.prevent="submit">
            <header class="flex flex-wrap items-center gap-4">
                <h1 class="text-xl font-semibold">Receive <span class="font-mono">{{ po.number }}</span></h1>
                <span class="text-sm">{{ po.supplier }} → {{ po.site }}</span>
                <Button type="button" variant="outline" size="sm" class="ml-auto" @click="receiveAllOutstanding">Fill all outstanding</Button>
            </header>
            <div class="flex flex-wrap gap-2">
                <Input v-model="form.supplier_ref" placeholder="Delivery docket / invoice no." class="w-64" />
                <Input v-model="form.notes" placeholder="Notes" class="flex-1" />
            </div>
            <p class="text-xs text-muted-foreground">Accepted stock goes to the chosen bin. Damaged stock is booked into quarantine (counted, not sellable). Rejected stock went back with the driver and is not stocked.</p>
            <InputError :message="errors.receipt" />

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Product</th><th>Outstanding</th><th class="w-40">Accepted (packs + loose)</th><th class="w-20">Damaged</th><th class="w-20">Rejected</th>
                        <th>Bin</th><th>Lot / best-before</th><th>Reason / note</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in form.rows" :key="i" class="border-b align-top" :class="{ 'bg-amber-50 dark:bg-amber-950': isOver(r.purchase_order_line_id) }">
                        <td class="py-2">
                            <span class="font-mono">{{ lineOf(r).product.sku }}</span> {{ lineOf(r).product.name }}
                            <div class="text-xs text-muted-foreground">{{ lineOf(r).unit }} ×{{ lineOf(r).base_qty_per_unit }}</div>
                        </td>
                        <td class="tabular-nums">{{ lineOf(r).outstanding_base }} {{ lineOf(r).product.base_unit }}</td>
                        <td>
                            <div class="flex gap-1">
                                <Input v-model.number="r.packs" type="number" min="0" class="h-8 w-20" :placeholder="lineOf(r).unit" />
                                <Input v-if="lineOf(r).base_qty_per_unit > 1" v-model.number="r.loose" type="number" min="0" class="h-8 w-16" placeholder="loose" />
                            </div>
                            <div class="text-xs text-muted-foreground">= {{ acceptedBase(r) }} {{ lineOf(r).product.base_unit }}</div>
                        </td>
                        <td><Input v-model.number="r.damaged" type="number" min="0" class="h-8" /></td>
                        <td><Input v-model.number="r.rejected" type="number" min="0" class="h-8" /></td>
                        <td>
                            <select v-model="r.location_id" class="h-8 rounded-md border bg-background px-1 text-sm">
                                <option v-for="b in bins" :key="b.id" :value="b.id">{{ b.label }}</option>
                            </select>
                        </td>
                        <td>
                            <template v-if="lineOf(r).product.is_batch_tracked">
                                <Input v-model="r.lot_code" placeholder="Lot" class="mb-1 h-8 w-28" />
                                <Input v-model="r.best_before" type="date" class="h-8 w-36" />
                            </template>
                        </td>
                        <td>
                            <select v-model="r.discrepancy_reason" class="mb-1 h-8 rounded-md border bg-background px-1 text-sm">
                                <option :value="null">—</option>
                                <option v-for="reason in reasons" :key="reason" :value="reason">{{ reason.replace('_', ' ') }}</option>
                            </select>
                            <Input v-model="r.note" placeholder="Note" class="h-8" />
                        </td>
                        <td><Button v-if="lineOf(r).product.is_batch_tracked" type="button" variant="ghost" size="sm" title="Another lot" @click="splitRow(i)">+ lot</Button></td>
                    </tr>
                </tbody>
            </table>

            <div v-if="anyOver" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
                Highlighted lines exceed the outstanding quantity.
                <label v-if="canOverReceive" class="ml-2 inline-flex items-center gap-2"><input type="checkbox" v-model="form.allow_over_receipt" /> Accept the over-receipt</label>
                <span v-else> A purchasing manager must accept over-receipts.</span>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">Post receipt</Button>
                <Button variant="ghost" as-child><Link :href="route('purchase-orders.show', po.id)">Cancel</Link></Button>
            </div>
        </form>
    </AppLayout>
</template>
