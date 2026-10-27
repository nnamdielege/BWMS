<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
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
    cancelled: number;
    unit_price: string | null;
    pick_lists: { id: number; number: string; status: string }[];
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
        run: { id: number; label: string; area_id: number; date: string } | null;
        run_assignment: string;
        delivery_address: string;
        created_at: string;
        created_by: string | null;
        lines: Line[];
    };
    can: { manage: boolean };
    deliveryAreas: { id: number; name: string }[];
}>();
const runForm = useForm({ delivery_area_id: props.order.run?.area_id ?? null, run_date: props.order.run?.date ?? '' });
const editRun = ref(false);
const saveRun = () => runForm.put(route('orders.run', props.order.id), { preserveScroll: true, onSuccess: () => (editRun.value = false) });

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
</script>

<template>
    <Head :title="order.number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="font-mono text-xl font-semibold">{{ order.number }}</h1>
                <span class="rounded bg-muted px-2 py-0.5 text-sm">{{ order.status_label }}</span>
                <span class="text-sm capitalize text-muted-foreground">{{ order.channel }}<span v-if="order.external_ref"> · {{ order.external_ref }} (v{{ order.external_version }})</span></span>
                <div class="ml-auto flex gap-2">
                    <Button v-if="can.manage && (order.oversold || order.needs_review)" variant="outline" @click="clearFlags">Mark flags handled</Button>
                    <Button v-if="can.manage" variant="ghost" @click="cancel">Cancel order</Button>
                </div>
            </header>
            <InputError :message="errors.order" />

            <div v-if="order.oversold" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:bg-red-950 dark:text-red-200">
                Accepted beyond available stock{{ order.oversold_at ? ` on ${fmt(order.oversold_at)}` : '' }}. Decide: short-ship, substitute or contact the customer.
            </div>
            <div v-if="order.needs_review" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
                The channel changed this order while it was being picked, or reduced it below what was already dispatched. Check the pick list.
            </div>

            <div class="grid gap-4 text-sm sm:grid-cols-4">
                <div><div class="text-muted-foreground">Customer</div>{{ order.customer?.name ?? '—' }}</div>
                <div><div class="text-muted-foreground">Fulfil from</div>{{ order.site }}</div>
                <div><div class="text-muted-foreground">Delivery date</div>{{ order.requested_delivery_date ?? '—' }}</div>
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
                        <th class="text-right">Cancelled</th><th>Pick lists</th><th class="text-right">Price</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in order.lines" :key="l.id" class="border-b">
                        <td class="py-2"><span class="font-mono">{{ l.product.sku }}</span> {{ l.product.name }}</td>
                        <td>{{ l.qty }} × {{ l.unit }}<span v-if="l.base_qty_per_unit > 1" class="text-xs text-muted-foreground"> ({{ l.qty_base }} {{ l.product.base_unit }})</span></td>
                        <td class="text-right tabular-nums">{{ l.reserved }}</td>
                        <td class="text-right tabular-nums">{{ l.dispatched }}</td>
                        <td class="text-right tabular-nums">{{ l.cancelled || '' }}</td>
                        <td>
                            <Link v-for="p in l.pick_lists" :key="p.id" :href="route('pick-lists.show', p.id)" class="mr-2 font-mono underline">{{ p.number }}</Link>
                        </td>
                        <td class="text-right tabular-nums">{{ l.unit_price ? `$${Number(l.unit_price).toFixed(2)}` : '' }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="text-xs text-muted-foreground">Reserved / dispatched / cancelled are in base units. Stock leaves the ledger at dispatch; until then it is reserved.</p>
            <p v-if="order.notes" class="whitespace-pre-line text-sm"><span class="text-muted-foreground">Notes:</span> {{ order.notes }}</p>
            <p class="text-xs text-muted-foreground">Created {{ fmt(order.created_at) }}{{ order.created_by ? ` by ${order.created_by}` : '' }}</p>
        </div>
    </AppLayout>
</template>
