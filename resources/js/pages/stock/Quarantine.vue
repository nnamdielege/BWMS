<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Line {
    key: string;
    product_id: number;
    sku: string;
    name: string;
    base_unit: string;
    location_id: number;
    bin: string;
    batch_id: number | null;
    batch: string | null;
    best_before: string | null;
    expired: boolean;
    qty: number;
    unit_cost: number | null;
    value: number;
    since: string | null;
    days: number | null;
    reason: string | null;
    reason_note: string | null;
    supplier_id: number | null;
    supplier: string | null;
}
type Action = 'write-off' | 'return' | 'release';

const props = defineProps<{
    register: { rows: Line[]; totals: { lines: number; units: number; value: number } };
    reasons: { id: number; name: string; requires_note: boolean }[];
    suppliers: { id: number; name: string }[];
    release_bins: { id: number; label: string }[];
    can: { release: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock on hand', href: '/stock' },
    { title: 'Quarantine', href: '/stock/quarantine' },
];
const money = (n: number | null) => (n === null ? '—' : `$${n.toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);

const open = ref<{ key: string; action: Action } | null>(null);
const form = useForm({
    client_token: '',
    product_id: 0,
    location_id: 0,
    batch_id: null as number | null,
    qty: 0,
    reason_code_id: null as number | null,
    supplier_id: null as number | null,
    to_location_id: props.release_bins[0]?.id ?? null,
    note: '',
});
const start = (l: Line, action: Action) => {
    form.clearErrors();
    Object.assign(form, {
        client_token: crypto.randomUUID(),
        product_id: l.product_id,
        location_id: l.location_id,
        batch_id: l.batch_id,
        qty: l.qty,
        reason_code_id: action === 'write-off' && l.expired ? (props.reasons.find((r) => r.name === 'Past best-before')?.id ?? null) : null,
        supplier_id: l.supplier_id,
        note: '',
    });
    open.value = { key: l.key, action };
};
const routes: Record<Action, string> = { 'write-off': 'quarantine.write-off', return: 'quarantine.return', release: 'quarantine.release' };
const submit = () => {
    if (!open.value) return;
    form.post(route(routes[open.value.action]), { preserveScroll: true, onSuccess: () => (open.value = null) });
};
const selectedReason = computed(() => props.reasons.find((r) => r.id === form.reason_code_id));
const firstError = computed(() => Object.values(form.errors)[0]);
</script>

<template>
    <Head title="Quarantine" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-baseline gap-4">
                <h1 class="mr-auto text-xl font-semibold">Quarantine</h1>
                <span class="text-sm">{{ register.totals.lines }} line{{ register.totals.lines === 1 ? '' : 's' }}</span>
                <span class="text-sm">{{ register.totals.units }} units</span>
                <span class="text-sm">At cost <strong class="tabular-nums">{{ money(register.totals.value) }}</strong></span>
            </header>
            <p class="text-xs text-muted-foreground">
                Everything in quarantine bins. Value at today's average cost, ex GST. Days count from when the line last arrived in an empty bin. Write off
                (counts as spoilage), return to the supplier (opens a supplier credit at cost) or release back to a sellable bin (not once past best-before).
            </p>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[64rem] text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-2">Product</th><th>Bin</th><th>Batch</th><th>Best before</th><th class="text-right">Qty</th>
                            <th class="text-right">Value at cost</th><th class="text-right">Days</th><th>Reason</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="l in register.rows" :key="l.key">
                            <tr class="border-b align-top">
                                <td class="py-2"><Link :href="route('stock.show', l.product_id)" class="font-mono underline">{{ l.sku }}</Link> {{ l.name }}</td>
                                <td class="text-xs">{{ l.bin }}</td>
                                <td>{{ l.batch ?? '—' }}</td>
                                <td class="whitespace-nowrap tabular-nums">
                                    {{ l.best_before ?? '—' }}
                                    <span v-if="l.expired" class="ml-1 rounded bg-red-100 px-1 text-xs text-red-800">past</span>
                                </td>
                                <td class="text-right tabular-nums">{{ l.qty }} {{ l.base_unit }}</td>
                                <td class="text-right tabular-nums">{{ l.unit_cost === null ? 'no cost' : money(l.value) }}</td>
                                <td class="text-right tabular-nums" :title="l.since ? `Since ${l.since}` : ''">{{ l.days ?? '—' }}</td>
                                <td>{{ l.reason ?? 'Not recorded' }}<div v-if="l.reason_note" class="text-xs text-muted-foreground">{{ l.reason_note }}</div></td>
                                <td class="whitespace-nowrap text-right">
                                    <Button size="sm" variant="ghost" @click="start(l, 'write-off')">Write off</Button>
                                    <Button size="sm" variant="ghost" @click="start(l, 'return')">Return</Button>
                                    <Button v-if="can.release" size="sm" variant="ghost" :disabled="l.expired" :title="l.expired ? 'Past best-before: cannot be sold' : ''" @click="start(l, 'release')">
                                        Release
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="open?.key === l.key" class="border-b bg-muted/40">
                                <td colspan="9" class="p-3">
                                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit">
                                        <strong class="w-full text-sm">
                                            {{ open.action === 'write-off' ? 'Write off' : open.action === 'return' ? 'Return to supplier' : 'Release back to stock' }}
                                        </strong>
                                        <label class="text-sm">
                                            <span class="block text-muted-foreground">Qty ({{ l.base_unit }})</span>
                                            <input v-model.number="form.qty" type="number" min="1" :max="l.qty" class="h-9 w-24 rounded-md border bg-background px-2" required />
                                        </label>
                                        <label v-if="open.action === 'write-off'" class="text-sm">
                                            <span class="block text-muted-foreground">Reason</span>
                                            <select v-model="form.reason_code_id" class="h-9 rounded-md border bg-background px-2" required>
                                                <option :value="null" disabled>Choose…</option>
                                                <option v-for="r in reasons" :key="r.id" :value="r.id">{{ r.name }}</option>
                                            </select>
                                        </label>
                                        <label v-if="open.action === 'return'" class="text-sm">
                                            <span class="block text-muted-foreground">Supplier</span>
                                            <select v-model="form.supplier_id" class="h-9 rounded-md border bg-background px-2" required>
                                                <option :value="null" disabled>Choose…</option>
                                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                                            </select>
                                        </label>
                                        <label v-if="open.action === 'release'" class="text-sm">
                                            <span class="block text-muted-foreground">To bin</span>
                                            <select v-model="form.to_location_id" class="h-9 rounded-md border bg-background px-2" required>
                                                <option v-for="b in release_bins" :key="b.id" :value="b.id">{{ b.label }}</option>
                                            </select>
                                        </label>
                                        <label class="min-w-64 flex-1 text-sm">
                                            <span class="block text-muted-foreground">
                                                {{ open.action === 'release' ? 'Why it can be sold again' : 'Note' }}
                                                <template v-if="open.action === 'write-off' && selectedReason?.requires_note"> (required)</template>
                                            </span>
                                            <input
                                                v-model="form.note"
                                                class="h-9 w-full rounded-md border bg-background px-2"
                                                :required="open.action === 'release' || (open.action === 'write-off' && selectedReason?.requires_note)"
                                            />
                                        </label>
                                        <span v-if="open.action === 'return' && l.unit_cost !== null" class="text-sm text-muted-foreground">
                                            Credit expected {{ money(form.qty * l.unit_cost) }}
                                        </span>
                                        <Button type="submit" :disabled="form.processing">Confirm</Button>
                                        <Button type="button" variant="ghost" @click="open = null">Cancel</Button>
                                        <InputError class="w-full" :message="firstError" />
                                    </form>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!register.rows.length">
                            <td colspan="9" class="py-6 text-center text-muted-foreground">Nothing in quarantine.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
