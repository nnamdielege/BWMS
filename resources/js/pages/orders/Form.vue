<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ProductPicker, { type PickedProduct } from '@/components/ProductPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type Line = { product: PickedProduct; product_unit_id: number; qty: number; unit_price: string | null };

const props = defineProps<{
    customers: { id: number; code: string; name: string }[];
    sites: { id: number; name: string }[];
    today: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: 'New order', href: '#' },
];

const form = useForm({
    customer_id: null as number | null,
    site_id: props.sites[0]?.id ?? null,
    requested_delivery_date: '',
    notes: '',
    short_ship_policy: 'backorder',
    allow_backorder: false,
    lines: [] as Line[],
});

const add = (p: PickedProduct) => {
    const unit = p.units.find((u) => u.default_sale && u.sellable) ?? p.units.find((u) => u.sellable) ?? p.units[0];
    form.lines.push({ product: p, product_unit_id: unit.id, qty: 1, unit_price: p.sell_price });
};
const unitOf = (l: Line) => l.product.units.find((u) => u.id === l.product_unit_id);
const errors = computed(() => usePage().props.errors as Record<string, string>);

const submit = () =>
    form
        .transform((d) => ({
            ...d,
            lines: d.lines.map((l: Line) => ({ product_id: l.product.id, product_unit_id: l.product_unit_id, qty: l.qty, unit_price: l.unit_price })),
        }))
        .post(route('orders.store'));
</script>

<template>
    <Head title="New order" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="space-y-6 p-4" @submit.prevent="submit">
            <div class="grid max-w-4xl gap-4 sm:grid-cols-3">
                <div class="grid gap-1">
                    <Label>Customer</Label>
                    <select v-model="form.customer_id" class="h-9 rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>Choose…</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                    </select>
                    <InputError :message="form.errors.customer_id" />
                </div>
                <div class="grid gap-1">
                    <Label>Fulfil from</Label>
                    <select v-model="form.site_id" class="h-9 rounded-md border bg-background px-2 text-sm" required>
                        <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1"><Label>Delivery date</Label><Input v-model="form.requested_delivery_date" type="date" :min="today" /></div>
                <div class="grid gap-1">
                    <Label>If short at picking</Label>
                    <select v-model="form.short_ship_policy" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option value="backorder">Backorder the rest</option>
                        <option value="cancel">Cancel the rest</option>
                    </select>
                </div>
            </div>

            <section class="space-y-2">
                <h2 class="font-semibold">Lines</h2>
                <ProductPicker class="max-w-lg" @pick="add" />
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="py-2">Product</th><th>Pack</th><th class="w-24">Qty</th><th class="w-32">Unit price</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i" class="border-b align-top">
                            <td class="py-2"><span class="font-mono">{{ l.product.sku }}</span> {{ l.product.name }}</td>
                            <td>
                                <select v-model="l.product_unit_id" class="h-8 rounded-md border bg-background px-1 text-sm">
                                    <option v-for="u in l.product.units.filter((x) => x.sellable)" :key="u.id" :value="u.id">{{ u.name }}{{ u.base_qty > 1 ? ` ×${u.base_qty}` : '' }}</option>
                                </select>
                                <div class="text-xs text-muted-foreground">= {{ (unitOf(l)?.base_qty ?? 1) * (l.qty || 0) }} {{ l.product.base_unit }}</div>
                            </td>
                            <td><Input v-model.number="l.qty" type="number" min="1" class="h-8" required /></td>
                            <td><Input v-model="l.unit_price" type="number" min="0" step="0.01" class="h-8" /></td>
                            <td class="text-right"><Button type="button" variant="ghost" size="sm" @click="form.lines.splice(i, 1)">Remove</Button></td>
                        </tr>
                        <tr v-if="!form.lines.length"><td colspan="5" class="py-6 text-center text-muted-foreground">Add products above.</td></tr>
                    </tbody>
                </table>
                <InputError :message="form.errors.lines || errors.order" />
            </section>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="form.allow_backorder" />
                Accept even if not enough stock is available (order is flagged oversold for follow-up)
            </label>
            <div class="grid max-w-3xl gap-1"><Label>Notes</Label><textarea v-model="form.notes" rows="2" class="rounded-md border bg-background p-2 text-sm" /></div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || !form.lines.length">Create order &amp; reserve stock</Button>
                <Button variant="ghost" as-child><Link :href="route('orders.index')">Cancel</Link></Button>
            </div>
        </form>
    </AppLayout>
</template>
