<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import MovementTable, { type MovementRow } from '@/components/MovementTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Option {
    id: number;
    label: string;
}
interface Reason {
    id: number;
    code: string;
    name: string;
    direction: 'in' | 'out' | 'both';
    requires_note: boolean;
    movement_type: string;
}

const props = defineProps<{
    product: { id: number; sku: string; name: string; base_unit: string; is_batch_tracked: boolean; units: { id: number; name: string; base_qty: number }[] };
    onHand: number;
    sellable: number;
    reserved: number;
    available: number;
    balances: {
        location_id: number;
        location: string;
        location_flags: { sellable: boolean; quarantine: boolean };
        batch_id: number | null;
        batch: string | null;
        best_before: string | null;
        expired: boolean;
        on_hand: number;
    }[];
    movements: MovementRow[];
    bins: Option[];
    batches: Option[];
    reasons: Reason[];
    can: { adjust: boolean; transfer: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock on hand', href: '/stock' },
    { title: props.product.sku, href: '#' },
];

// A fresh UUID per form instance makes a double-click or retry idempotent server-side.
const token = () => crypto.randomUUID();

const adjust = useForm({
    client_token: token(),
    location_id: null as number | null,
    batch_id: null as number | null,
    new_batch: { lot_code: '', best_before: '' },
    direction: 'out' as 'in' | 'out',
    amount: 1,
    qty: 0, // signed value sent to the server, derived from direction + amount
    reason_code_id: null as number | null,
    note: '',
});
const reasonsForDirection = computed(() => props.reasons.filter((r) => r.direction === 'both' || r.direction === adjust.direction));
const selectedReason = computed(() => props.reasons.find((r) => r.id === adjust.reason_code_id));
const submitAdjust = () => {
    adjust.qty = adjust.direction === 'out' ? -Math.abs(adjust.amount) : Math.abs(adjust.amount);
    adjust.post(route('stock.adjust', props.product.id), {
        preserveScroll: true,
        onSuccess: () => {
            adjust.reset();
            adjust.client_token = token();
        },
    });
};

const transfer = useForm({
    client_token: token(),
    from_location_id: null as number | null,
    to_location_id: null as number | null,
    batch_id: null as number | null,
    qty: 1,
    note: '',
});
const pickBalance = (b: (typeof props.balances)[number]) => {
    transfer.from_location_id = b.location_id;
    transfer.batch_id = b.batch_id;
};
const submitTransfer = () =>
    transfer.post(route('stock.transfer', props.product.id), {
        preserveScroll: true,
        onSuccess: () => {
            transfer.reset();
            transfer.client_token = token();
        },
    });
const packs = computed(() => props.product.units.filter((u) => u.base_qty > 1));
</script>

<template>
    <Head :title="`Stock — ${product.sku}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <header class="flex flex-wrap items-baseline gap-4">
                <h1 class="text-xl font-semibold">{{ product.sku }} — {{ product.name }}</h1>
                <span class="text-sm">On hand <strong class="tabular-nums">{{ onHand }}</strong> {{ product.base_unit }}</span>
                <span class="text-sm">Sellable <strong class="tabular-nums">{{ sellable }}</strong></span>
                <span class="text-sm" title="On open orders, not yet dispatched">Reserved <strong class="tabular-nums">{{ reserved }}</strong></span>
                <span class="text-sm" title="What Ordermentum and the portal see">
                    Available <strong class="tabular-nums" :class="{ 'text-red-600': available < 0 }">{{ available }}</strong>
                </span>
                <span v-for="u in packs" :key="u.id" class="text-sm text-muted-foreground">= {{ Math.floor(Math.max(0, available) / u.base_qty) }} × {{ u.name }}</span>
                <Link :href="route('products.edit', product.id)" class="ml-auto text-sm underline">Product details</Link>
            </header>

            <section>
                <h2 class="mb-2 font-semibold">By location{{ product.is_batch_tracked ? ' and batch (earliest best-before first)' : '' }}</h2>
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="py-2">Location</th><th v-if="product.is_batch_tracked">Batch</th><th class="text-right">On hand</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in balances" :key="`${b.location_id}-${b.batch_id}`" class="border-b">
                            <td class="py-2">
                                {{ b.location }}
                                <span v-if="b.location_flags.quarantine" class="ml-1 rounded bg-red-100 px-1 text-xs text-red-800">quarantine</span>
                                <span v-else-if="!b.location_flags.sellable" class="ml-1 rounded bg-muted px-1 text-xs">not sellable</span>
                            </td>
                            <td v-if="product.is_batch_tracked">
                                {{ b.batch }} <span v-if="b.expired" class="rounded bg-red-100 px-1 text-xs text-red-800">expired</span>
                            </td>
                            <td class="text-right tabular-nums" :class="{ 'text-red-600': b.on_hand < 0 }">{{ b.on_hand }}</td>
                            <td class="text-right"><Button v-if="can.transfer" size="sm" variant="ghost" @click="pickBalance(b)">Move…</Button></td>
                        </tr>
                        <tr v-if="!balances.length"><td colspan="4" class="py-4 text-center text-muted-foreground">No stock.</td></tr>
                    </tbody>
                </table>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <form v-if="can.adjust" class="space-y-3 rounded-lg border p-4" @submit.prevent="submitAdjust">
                    <h2 class="font-semibold">Adjust / write off</h2>
                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-1"><input type="radio" value="out" v-model="adjust.direction" @change="adjust.reason_code_id = null" /> Remove</label>
                        <label class="flex items-center gap-1"><input type="radio" value="in" v-model="adjust.direction" @change="adjust.reason_code_id = null" /> Add</label>
                    </div>
                    <div class="grid gap-1">
                        <Label>Quantity ({{ product.base_unit }})</Label>
                        <Input v-model.number="adjust.amount" type="number" min="1" required />
                    </div>
                    <select v-model="adjust.location_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>Bin…</option>
                        <option v-for="b in bins" :key="b.id" :value="b.id">{{ b.label }}</option>
                    </select>
                    <template v-if="product.is_batch_tracked">
                        <select v-model="adjust.batch_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
                            <option :value="null">{{ adjust.direction === 'in' ? 'New batch…' : 'Batch…' }}</option>
                            <option v-for="b in batches" :key="b.id" :value="b.id">{{ b.label }}</option>
                        </select>
                        <div v-if="adjust.direction === 'in' && !adjust.batch_id" class="grid grid-cols-2 gap-2">
                            <Input v-model="adjust.new_batch.lot_code" placeholder="Lot code" />
                            <Input v-model="adjust.new_batch.best_before" type="date" />
                        </div>
                    </template>
                    <select v-model="adjust.reason_code_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>Reason…</option>
                        <option v-for="r in reasonsForDirection" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                    <textarea
                        v-model="adjust.note"
                        rows="2"
                        class="w-full rounded-md border bg-background p-2 text-sm"
                        :placeholder="selectedReason?.requires_note ? 'Note (required)' : 'Note (optional)'"
                        :required="selectedReason?.requires_note"
                    />
                    <InputError :message="adjust.errors.qty || adjust.errors.location_id || adjust.errors.reason_code_id" />
                    <Button type="submit" :disabled="adjust.processing">Post adjustment</Button>
                </form>

                <form v-if="can.transfer" class="space-y-3 rounded-lg border p-4" @submit.prevent="submitTransfer">
                    <h2 class="font-semibold">Move between bins</h2>
                    <select v-model="transfer.from_location_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>From bin…</option>
                        <option v-for="b in bins" :key="b.id" :value="b.id">{{ b.label }}</option>
                    </select>
                    <select v-if="product.is_batch_tracked" v-model="transfer.batch_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>Batch…</option>
                        <option v-for="b in batches" :key="b.id" :value="b.id">{{ b.label }}</option>
                    </select>
                    <select v-model="transfer.to_location_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>To bin…</option>
                        <option v-for="b in bins" :key="b.id" :value="b.id">{{ b.label }}</option>
                    </select>
                    <Input v-model.number="transfer.qty" type="number" min="1" required />
                    <InputError :message="transfer.errors.qty || transfer.errors.to_location_id" />
                    <Button type="submit" :disabled="transfer.processing">Move stock</Button>
                </form>
            </div>

            <section>
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Recent movements</h2>
                    <Link :href="route('stock.movements', { sku: product.sku })" class="text-sm underline">All movements</Link>
                </div>
                <MovementTable :movements="movements" />
            </section>
        </div>
    </AppLayout>
</template>
