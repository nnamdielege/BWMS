<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ProductPicker, { type PickedProduct } from '@/components/ProductPicker.vue';
import DataTable from '@/components/reports/DataTable.vue';
import type { Column } from '@/components/reports/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { money } from '@/lib/money';
import { movementsHref } from '@/lib/movements';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Slot {
    key: string;
    location_id: number;
    batch_id: number | null;
    label: string;
    on_hand: number;
}
interface Product {
    id: number;
    sku: string;
    name: string;
    base_unit: string;
    avg_cost: number;
    units: { id: number; name: string; base_qty: number; default: boolean }[];
    slots: Slot[];
}
const props = defineProps<{
    reasons: { id: number; code: string; name: string }[];
    approvers: { id: number; name: string }[];
    customers: { id: number; name: string }[];
    product: Product | null;
    recent: Record<string, unknown>[];
    month: { spent: number; budget: number | null; label: string };
    can: { budget: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Give away', href: '/stock/give-away' }];
const me = (usePage().props.auth as { user: { id: number } }).user.id;

const defaultUnit = (p: Product | null) => (p ? ((p.units.find((u) => u.default) ?? p.units[p.units.length - 1] ?? null)?.id ?? null) : null);
const form = useForm({
    client_token: crypto.randomUUID(),
    product_id: props.product?.id ?? null,
    product_unit_id: defaultUnit(props.product),
    packs: 1 as number | null,
    slot: props.product?.slots[0]?.key ?? '',
    reason_code_id: props.reasons[0]?.id ?? null,
    to: 'customer' as 'customer' | 'name',
    customer_id: null as number | null,
    recipient_name: '',
    purpose: '',
    approved_by: props.approvers.some((a) => a.id === me) ? me : null,
});
watch(
    () => props.product,
    (p) => {
        form.product_id = p?.id ?? null;
        form.product_unit_id = defaultUnit(p);
        form.slot = p?.slots[0]?.key ?? '';
    },
);

const pick = (p: PickedProduct) =>
    router.get(route('giveaways.index'), { product_id: p.id }, { only: ['product'], preserveState: true, preserveScroll: true });
const unit = computed(() => props.product?.units.find((u) => u.id === form.product_unit_id) ?? null);
const slot = computed(() => props.product?.slots.find((s) => s.key === form.slot) ?? null);
const baseQty = computed(() => Math.round((form.packs ?? 0) * (unit.value?.base_qty ?? 1)));
const cost = computed(() => (props.product ? baseQty.value * props.product.avg_cost : 0));

const submit = () =>
    form
        .transform((d) => ({
            client_token: d.client_token,
            product_id: d.product_id,
            product_unit_id: d.product_unit_id,
            packs: d.packs,
            location_id: slot.value?.location_id,
            batch_id: slot.value?.batch_id,
            reason_code_id: d.reason_code_id,
            customer_id: d.to === 'customer' ? d.customer_id : null,
            recipient_name: d.to === 'name' ? d.recipient_name : null,
            purpose: d.purpose,
            approved_by: d.approved_by,
        }))
        .post(route('giveaways.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.client_token = crypto.randomUUID();
                form.packs = 1;
                form.purpose = '';
            },
        });

/** Errors on fields the form sends but doesn't hold (the bin comes from the chosen slot). */
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);

const budgetForm = useForm({ budget: props.month.budget as number | null });
const editBudget = ref(false);
const saveBudget = () => budgetForm.put(route('giveaways.budget'), { preserveScroll: true, onSuccess: () => (editBudget.value = false) });
const used = computed(() => (props.month.budget ? Math.min(100, (props.month.spent / props.month.budget) * 100) : 0));

const recentColumns: Column[] = [
    { key: 'date', label: 'When' },
    { key: 'reason', label: 'Reason' },
    { key: 'recipient', label: 'To', sub: (r) => r.purpose },
    { key: 'sku', label: 'Product', sub: (r) => r.name },
    { key: 'qty', label: 'Qty' },
    { key: 'value', label: 'Cost', kind: 'money', href: (r) => movementsHref([r.stock_movement_id]) },
    { key: 'approved_by', label: 'Approved by', sub: (r) => `entered by ${r.by}` },
];
</script>

<template>
    <Head title="Give away" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid gap-6 p-4 lg:grid-cols-[minmax(0,32rem)_1fr]">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <h1 class="text-xl font-semibold">Give away</h1>
                    <p class="text-sm text-muted-foreground">Samples, gifts, favours, staff and internal use. Written off at today's cost.</p>
                </div>

                <div class="grid gap-1 text-sm">
                    <span class="font-medium">Product</span>
                    <div v-if="product" class="flex items-center gap-2 rounded-md border px-3 py-2">
                        <span class="font-mono">{{ product.sku }}</span> {{ product.name }}
                        <button type="button" class="ml-auto text-xs underline" @click="router.get(route('giveaways.index'))">Change</button>
                    </div>
                    <ProductPicker v-else placeholder="SKU, name or scan" @pick="pick" />
                    <InputError :message="form.errors.product_id" />
                </div>

                <template v-if="product">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <label class="grid gap-1">
                            <span class="font-medium">Quantity</span>
                            <input
                                v-model.number="form.packs"
                                type="number"
                                min="0"
                                step="any"
                                class="h-9 rounded-md border bg-background px-2"
                                required
                            />
                        </label>
                        <label class="grid gap-1">
                            <span class="font-medium">Pack</span>
                            <select v-model="form.product_unit_id" class="h-9 rounded-md border bg-background px-2">
                                <option v-for="u in product.units" :key="u.id" :value="u.id">
                                    {{ u.name }}{{ u.base_qty > 1 ? ` ×${u.base_qty}` : '' }}
                                </option>
                            </select>
                        </label>
                    </div>
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">From</span>
                        <select v-model="form.slot" class="h-9 rounded-md border bg-background px-2" required>
                            <option v-for="s in product.slots" :key="s.key" :value="s.key">
                                {{ s.label }} ({{ s.on_hand }} {{ product.base_unit }})
                            </option>
                        </select>
                        <span v-if="!product.slots.length" class="text-red-700">No stock on hand.</span>
                    </label>
                    <p class="text-xs text-muted-foreground">{{ baseQty }} {{ product.base_unit }} · about {{ money(cost) }} at cost</p>
                    <InputError :message="form.errors.packs ?? serverErrors.location_id" />
                </template>

                <fieldset class="grid gap-1 text-sm">
                    <legend class="mb-1 font-medium">Reason</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="r in reasons"
                            :key="r.id"
                            class="flex items-center gap-1 rounded-md border px-2 py-1"
                            :class="form.reason_code_id === r.id ? 'bg-muted font-medium' : ''"
                        >
                            <input v-model="form.reason_code_id" type="radio" :value="r.id" class="sr-only" />{{ r.name }}
                        </label>
                    </div>
                    <InputError :message="form.errors.reason_code_id" />
                </fieldset>

                <div class="grid gap-1 text-sm">
                    <span class="font-medium">To</span>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-1"><input v-model="form.to" type="radio" value="customer" /> A customer</label>
                        <label class="flex items-center gap-1"><input v-model="form.to" type="radio" value="name" /> Someone else</label>
                    </div>
                    <select v-if="form.to === 'customer'" v-model="form.customer_id" class="h-9 rounded-md border bg-background px-2">
                        <option :value="null">Choose a customer…</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <input
                        v-else
                        v-model="form.recipient_name"
                        type="text"
                        maxlength="255"
                        placeholder="Name (staff member, prospect, charity…)"
                        class="h-9 rounded-md border bg-background px-2"
                    />
                    <InputError :message="form.errors.customer_id ?? form.errors.recipient_name" />
                </div>

                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Purpose</span>
                    <textarea
                        v-model="form.purpose"
                        rows="2"
                        maxlength="1000"
                        class="rounded-md border bg-background p-2"
                        placeholder="e.g. Tasting for their new menu"
                    />
                    <InputError :message="form.errors.purpose" />
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Approved by</span>
                    <select v-model="form.approved_by" class="h-9 rounded-md border bg-background px-2">
                        <option :value="null">Choose…</option>
                        <option v-for="a in approvers" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                    <InputError :message="form.errors.approved_by" />
                </label>

                <Button type="submit" :disabled="form.processing || !product || !product.slots.length">Record</Button>
            </form>

            <div class="space-y-4">
                <section class="rounded-lg border p-4 text-sm">
                    <div class="flex flex-wrap items-baseline gap-3">
                        <h2 class="mr-auto font-semibold">{{ month.label }}</h2>
                        <span class="tabular-nums">{{ money(month.spent) }} given away</span>
                        <span v-if="month.budget !== null" class="tabular-nums text-muted-foreground">of {{ money(month.budget) }} budget</span>
                        <button v-if="can.budget" type="button" class="underline" @click="editBudget = !editBudget">
                            {{ month.budget === null ? 'Set a budget' : 'Change' }}
                        </button>
                    </div>
                    <div v-if="month.budget" class="mt-2 h-2 rounded-full bg-muted">
                        <div
                            class="h-2 rounded-full"
                            :class="month.spent > month.budget ? 'bg-red-600' : 'bg-sky-600 dark:bg-sky-400'"
                            :style="{ width: `${used}%` }"
                        ></div>
                    </div>
                    <p v-if="month.budget !== null && month.spent > month.budget" class="mt-1 text-red-700 dark:text-red-400">
                        Over budget by {{ money(month.spent - month.budget) }}.
                    </p>
                    <form v-if="editBudget" class="mt-3 flex items-end gap-2" @submit.prevent="saveBudget">
                        <label class="grid gap-1">
                            <span class="text-muted-foreground">Monthly budget at cost ($, empty for none)</span>
                            <input
                                v-model.number="budgetForm.budget"
                                type="number"
                                min="0"
                                step="1"
                                class="h-9 w-40 rounded-md border bg-background px-2"
                            />
                        </label>
                        <Button type="submit" variant="outline" :disabled="budgetForm.processing">Save</Button>
                        <InputError :message="budgetForm.errors.budget" />
                    </form>
                </section>

                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-semibold">Recent</h2>
                    <DataTable :columns="recentColumns" :rows="recent" empty="Nothing given away yet." :limit="20" />
                </section>
            </div>
        </div>
    </AppLayout>
</template>
