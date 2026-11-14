<script setup lang="ts">
import HeldBadge, { type Hold } from '@/components/HeldBadge.vue';
import InputError from '@/components/InputError.vue';
import MovedBadge from '@/components/MovedBadge.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import SubstituteDialog, { type SubstituteTarget } from '@/components/SubstituteDialog.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { packLabel, unitLabel, type Pack } from '@/lib/packs';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Line {
    id: number;
    line_ref: string | null;
    product: { id: number; sku: string; name: string; base_unit: string };
    unit: string;
    base_qty_per_unit: number;
    qty: number;
    qty_base: number;
    reserved: number;
    dispatched: number;
    pack: Pack;
    cancelled: number;
    unit_price: string | null;
    pick_lists: { id: number; number: string; status: string }[];
    substituted: number;
    sub_label: string | null;
    outstanding: number;
}
interface Substitution {
    id: number;
    original: { sku: string; name: string };
    substitute: { sku: string; name: string };
    qty: string;
    price_difference: string | null;
    note: string;
    by: string | null;
    at: string;
    undone: { by: string | null; at: string } | null;
    can_undo: boolean;
}

const props = defineProps<{
    order: {
        id: number;
        number: string;
        channel: string;
        external_ref: string | null;
        external_version: number;
        status: string;
        status_label: string;
        oversold: boolean;
        oversold_at: string | null;
        needs_review: boolean;
        short_ship_policy: string;
        notes: string | null;
        cancel_reason: string | null;
        customer: { id: number; name: string; code: string } | null;
        site: string;
        requested_delivery_date: string | null;
        delivery_date: string | null;
        delivery_date_origin: string | null;
        moved_from: string | null;
        hold: Hold | null;
        on_pick_list: boolean;
        review_notes: string[];
        run: { id: number; label: string; area_id: number; date: string } | null;
        run_assignment: string;
        delivery_address: string;
        created_at: string;
        created_by: string | null;
        lines: Line[];
        substitutions: Substitution[];
    };
    can: { manage: boolean; release_hold: boolean };
    deliveryAreas: { id: number; name: string }[];
}>();
const runForm = useForm({ delivery_area_id: props.order.run?.area_id ?? null, run_date: props.order.run?.date ?? '' });
const editRun = ref(false);
const saveRun = () => runForm.put(route('orders.run', props.order.id), { preserveScroll: true, onSuccess: () => (editRun.value = false) });

const dateForm = useForm({ delivery_date: props.order.delivery_date ?? '' });
const editDate = ref(false);
const saveDate = () => dateForm.put(route('orders.delivery-date', props.order.id), { preserveScroll: true, onSuccess: () => (editDate.value = false) });

const releaseHold = () => confirm('Release this unpaid order to pick? It will be picked and delivered before payment.') && router.post(route('orders.release-hold', props.order.id), {}, { preserveScroll: true });
const undoMove = () => confirm('Put this order back on its original date and run?') && router.post(route('orders.consolidate.undo', props.order.id), {}, { preserveScroll: true });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: props.order.number, href: '#' },
];
const errors = computed(() => usePage().props.errors as Record<string, string>);
const fmt = (iso: string) => new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' });
const cancel = () => {
    const reason = prompt('Why is this order being cancelled?');
    if (reason) router.post(route('orders.cancel', props.order.id), { reason }, { preserveScroll: true });
};
const clearFlags = () => router.post(route('orders.clear-flags', props.order.id), {}, { preserveScroll: true });

const subTarget = ref<SubstituteTarget | null>(null);
const substitute = (l: Line) =>
    (subTarget.value = {
        orderId: props.order.id,
        lineId: l.id,
        sku: l.product.sku,
        name: l.product.name,
        pack: l.pack,
        unitPrice: l.unit_price,
        outstanding: l.outstanding,
        replaceBase: l.outstanding,
    });
const undoSubstitution = (s: Substitution) =>
    confirm(`Undo: put ${s.original.sku} back and remove the ${s.substitute.sku} line?`) &&
    router.post(route('orders.substitutions.undo', [props.order.id, s.id]), {}, { preserveScroll: true });
const money = (v: string | null) => (v === null ? '—' : `${Number(v) < 0 ? '−' : Number(v) > 0 ? '+' : ''}$${Math.abs(Number(v)).toFixed(2)}`);
</script>

<template>
    <Head :title="order.number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="font-mono text-xl font-semibold">{{ order.number }}</h1>
                <span class="rounded bg-muted px-2 py-0.5 text-sm">{{ order.status_label }}</span>
                <HeldBadge :hold="order.hold" />
                <SourceBadge :channel="order.channel" />
                <span v-if="order.external_ref" class="text-sm text-muted-foreground">{{ order.external_ref }} (v{{ order.external_version }})</span>
                <div class="ml-auto flex gap-2">
                    <Button v-if="can.release_hold" variant="outline" @click="releaseHold">Release to pick</Button>
                    <Button v-if="can.manage && (order.oversold || order.needs_review)" variant="outline" @click="clearFlags">Mark flags handled</Button>
                    <Button v-if="can.manage" variant="ghost" @click="cancel">Cancel order</Button>
                </div>
            </header>
            <InputError :message="errors.order || errors.consolidate" />

            <div v-if="order.oversold" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:bg-red-950 dark:text-red-200">
                Accepted beyond available stock{{ order.oversold_at ? ` on ${fmt(order.oversold_at)}` : '' }}. Decide: short-ship, substitute or contact the customer.
            </div>
            <div v-if="order.needs_review" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
                <ul v-if="order.review_notes.length" class="list-disc pl-5">
                    <li v-for="n in order.review_notes" :key="n">{{ n }}</li>
                </ul>
                <template v-else>The channel changed this order while it was being picked, or reduced it below what was already dispatched. Check the pick list.</template>
            </div>

            <div class="grid gap-4 text-sm sm:grid-cols-4">
                <div><div class="text-muted-foreground">Customer</div>{{ order.customer?.name ?? '—' }}</div>
                <div><div class="text-muted-foreground">Fulfil from</div>{{ order.site }}</div>
                <div>
                    <div class="text-muted-foreground">Delivery date</div>
                    {{ order.delivery_date ?? '—' }}
                    <span v-if="order.delivery_date_origin === 'manager'" class="text-xs text-muted-foreground">(set by a manager)</span>
                    <button v-if="can.manage" class="ml-2 text-xs underline" @click="editDate = !editDate">Change</button>
                    <div v-if="order.moved_from" class="mt-1">
                        <MovedBadge :from="order.moved_from" />
                        <button v-if="can.manage && !order.on_pick_list" class="ml-2 text-xs underline" @click="undoMove">Undo</button>
                    </div>
                    <form v-if="editDate" class="mt-2 flex items-center gap-2" @submit.prevent="saveDate">
                        <input v-model="dateForm.delivery_date" type="date" required class="h-9 rounded-md border bg-background px-2" aria-label="New delivery date" />
                        <button type="submit" class="h-9 rounded-md border px-3" :disabled="dateForm.processing">Save</button>
                    </form>
                    <InputError :message="dateForm.errors.delivery_date" />
                </div>
                <div><div class="text-muted-foreground">If short</div>{{ order.short_ship_policy === 'cancel' ? 'Cancel the rest' : 'Backorder' }}</div>
                <div v-if="order.cancel_reason" class="sm:col-span-4"><span class="text-muted-foreground">Cancelled:</span> {{ order.cancel_reason }}</div>
                <div class="sm:col-span-4">
                    <span class="text-muted-foreground">Run:</span>
                    <span :class="{ 'text-amber-700': !order.run }">{{ order.run?.label ?? 'Unassigned (address matches no delivery area)' }}</span>
                    <span v-if="order.run_assignment === 'manual'" class="ml-1 text-xs text-muted-foreground">(set by hand)</span>
                    <span class="ml-2 text-muted-foreground">{{ order.delivery_address }}</span>
                    <button v-if="can.manage" class="ml-2 text-xs underline" @click="editRun = !editRun">Change</button>
                    <form v-if="editRun" class="mt-2 flex flex-wrap items-center gap-2" @submit.prevent="saveRun">
                        <select v-model="runForm.delivery_area_id" class="h-9 rounded-md border bg-background px-2">
                            <option :value="null" disabled>Area…</option>
                            <option v-for="a in deliveryAreas" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <input v-model="runForm.run_date" type="date" class="h-9 rounded-md border bg-background px-2" />
                        <button type="submit" class="h-9 rounded-md border px-3" :disabled="runForm.processing || !runForm.delivery_area_id || !runForm.run_date">Save</button>
                        <span class="text-xs text-red-700">{{ errors.run || runForm.errors.run_date }}</span>
                    </form>
                </div>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Product</th><th>Ordered</th><th class="text-right">Reserved</th><th class="text-right">Dispatched</th>
                        <th class="text-right">Cancelled</th><th>Pick lists</th><th class="text-right">Price</th><th v-if="can.manage"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in order.lines" :key="l.id" class="border-b">
                        <td class="py-2">
                            <span class="font-mono">{{ l.product.sku }}</span> {{ l.product.name }}
                            <div v-if="l.sub_label" class="text-xs font-semibold text-amber-700 dark:text-amber-300">{{ l.sub_label }}</div>
                        </td>
                        <td>
                            <template v-if="l.sub_label">{{ packLabel(l.qty_base, l.pack) }}</template>
                            <template v-else>{{ l.qty }} × {{ l.unit }}<span v-if="l.base_qty_per_unit > 1" class="text-xs text-muted-foreground"> ({{ l.qty_base }} {{ unitLabel(l.product.base_unit, l.qty_base) }})</span></template>
                        </td>
                        <td class="text-right tabular-nums" :class="{ 'text-amber-700 dark:text-amber-300': l.reserved % l.pack.per !== 0 }">{{ packLabel(l.reserved, l.pack) }}</td>
                        <td class="text-right tabular-nums" :class="{ 'text-amber-700 dark:text-amber-300': l.dispatched % l.pack.per !== 0 }">{{ packLabel(l.dispatched, l.pack) }}</td>
                        <td class="text-right tabular-nums">
                            {{ l.cancelled ? packLabel(l.cancelled, l.pack) : '' }}
                            <div v-if="l.substituted" class="text-xs text-muted-foreground">{{ packLabel(l.substituted, l.pack) }} substituted</div>
                        </td>
                        <td>
                            <Link v-for="p in l.pick_lists" :key="p.id" :href="route('pick-lists.show', p.id)" class="mr-2 font-mono underline">{{ p.number }}</Link>
                        </td>
                        <td class="text-right tabular-nums">{{ l.unit_price ? `$${Number(l.unit_price).toFixed(2)}` : '' }}</td>
                        <td v-if="can.manage" class="text-right">
                            <button v-if="l.outstanding > 0" class="text-xs underline" @click="substitute(l)">Substitute</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <InputError :message="errors.substitute" />

            <section v-if="order.substitutions.length" class="space-y-2">
                <h2 class="font-semibold">Substitutions</h2>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="s in order.substitutions" :key="s.id" class="flex flex-wrap items-start gap-x-4 gap-y-1 p-3" :class="{ 'opacity-60': s.undone }">
                        <div class="flex-1">
                            <div>
                                <span class="font-mono">{{ s.original.sku }}</span> {{ s.original.name }} → <span class="font-mono">{{ s.substitute.sku }}</span> {{ s.substitute.name }} · {{ s.qty }}
                                <span v-if="s.undone" class="ml-1 rounded bg-muted px-1.5 text-xs">undone</span>
                            </div>
                            <div class="text-xs text-muted-foreground">
                                “{{ s.note }}” · {{ s.by }}, {{ fmt(s.at) }}<template v-if="s.undone"> · undone by {{ s.undone.by }}, {{ fmt(s.undone.at) }}</template>
                            </div>
                        </div>
                        <div class="tabular-nums" title="Substitute value − original value, ex GST">{{ money(s.price_difference) }}</div>
                        <button v-if="s.can_undo" class="text-xs underline" @click="undoSubstitution(s)">Undo</button>
                    </li>
                </ul>
            </section>
            <SubstituteDialog :target="subTarget" @close="subTarget = null" />
            <p class="text-xs text-muted-foreground">Reserved / dispatched / cancelled are in the line's pack plus loose units; amber means part of a pack. Stock leaves the ledger at dispatch; until then it is reserved.</p>
            <p v-if="order.notes" class="whitespace-pre-line text-sm"><span class="text-muted-foreground">Notes:</span> {{ order.notes }}</p>
            <p class="text-xs text-muted-foreground">Created {{ fmt(order.created_at) }}{{ order.created_by ? ` by ${order.created_by}` : '' }}</p>
        </div>
    </AppLayout>
</template>
