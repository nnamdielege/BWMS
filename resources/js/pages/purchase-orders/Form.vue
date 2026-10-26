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

type LineProduct = Pick<PickedProduct, 'id' | 'sku' | 'name' | 'base_unit' | 'avg_cost'> & { units: { id: number; name: string; base_qty: number; purchasable: boolean }[] };
type Line = { product: LineProduct; product_unit_id: number; qty_ordered: number; unit_cost: string; tax_rate: string | number; notes: string | null };

const props = defineProps<{
    po: {
        id: number;
        number: string;
        supplier_id: number;
        site_id: number;
        supplier_ref: string | null;
        notes: string | null;
        order_date: string;
        expected_date: string | null;
        lines: Line[];
    } | null;
    suppliers: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    today: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchase orders', href: '/purchase-orders' },
    { title: props.po ? props.po.number : 'New', href: '#' },
];

const form = useForm({
    supplier_id: props.po?.supplier_id ?? (null as number | null),
    site_id: props.po?.site_id ?? props.sites[0]?.id ?? null,
    order_date: props.po?.order_date ?? props.today,
    expected_date: props.po?.expected_date ?? '',
    supplier_ref: props.po?.supplier_ref ?? '',
    notes: props.po?.notes ?? '',
    lines: (props.po?.lines ?? []) as Line[],
});

const add = (p: PickedProduct) => {
    const unit = p.units.find((u) => u.default_purchase && u.purchasable) ?? p.units.find((u) => u.purchasable) ?? p.units[0];
    form.lines.push({
        product: p,
        product_unit_id: unit.id,
        qty_ordered: 1,
        unit_cost: (Number(p.avg_cost) * unit.base_qty).toFixed(2),
        tax_rate: 10,
        notes: null,
    });
};
const unitOf = (l: Line) => l.product.units.find((u) => u.id === l.product_unit_id);
const lineTotal = (l: Line) => (Number(l.unit_cost) || 0) * (l.qty_ordered || 0);
const subtotal = computed(() => form.lines.reduce((s, l) => s + lineTotal(l), 0));
const gst = computed(() => form.lines.reduce((s, l) => s + (lineTotal(l) * Number(l.tax_rate || 0)) / 100, 0));
const errors = computed(() => usePage().props.errors as Record<string, string>);

const submit = () => {
    const payload = (d: typeof form.data extends () => infer T ? T : never) => ({
        ...d,
        lines: d.lines.map((l: Line) => ({ product_id: l.product.id, product_unit_id: l.product_unit_id, qty_ordered: l.qty_ordered, unit_cost: l.unit_cost, tax_rate: l.tax_rate, notes: l.notes })),
    });
    const f = form.transform(payload);
    if (props.po) f.put(route('purchase-orders.update', props.po.id));
    else f.post(route('purchase-orders.store'));
};
</script>

<template>
    <Head :title="po ? `Edit ${po.number}` : 'New purchase order'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="space-y-6 p-4" @submit.prevent="submit">
            <div class="grid max-w-4xl gap-4 sm:grid-cols-3">
                <div class="grid gap-1">
                    <Label>Supplier</Label>
                    <select v-model="form.supplier_id" class="h-9 rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>Choose…</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <InputError :message="form.errors.supplier_id" />
                </div>
                <div class="grid gap-1">
                    <Label>Deliver to</Label>
                    <select v-model="form.site_id" class="h-9 rounded-md border bg-background px-2 text-sm" required>
                        <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1"><Label>Supplier reference</Label><Input v-model="form.supplier_ref" /></div>
                <div class="grid gap-1"><Label>Order date</Label><Input v-model="form.order_date" type="date" required /></div>
                <div class="grid gap-1"><Label>Required by</Label><Input v-model="form.expected_date" type="date" /><InputError :message="form.errors.expected_date" /></div>
            </div>

            <section class="space-y-2">
                <h2 class="font-semibold">Lines</h2>
                <ProductPicker class="max-w-lg" @pick="add" />
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="py-2">Product</th><th>Pack</th><th class="w-24">Qty</th><th class="w-32">Cost / pack (ex GST)</th><th class="w-20">GST %</th><th class="text-right">Line</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i" class="border-b align-top">
                            <td class="py-2"><span class="font-mono">{{ l.product.sku }}</span> {{ l.product.name }}</td>
                            <td>
                                <select v-model="l.product_unit_id" class="h-8 rounded-md border bg-background px-1 text-sm">
                                    <option v-for="u in l.product.units.filter((x) => x.purchasable)" :key="u.id" :value="u.id">{{ u.name }}{{ u.base_qty > 1 ? ` ×${u.base_qty}` : '' }}</option>
                                </select>
                                <div class="text-xs text-muted-foreground">= {{ (unitOf(l)?.base_qty ?? 1) * (l.qty_ordered || 0) }} {{ l.product.base_unit }}</div>
                            </td>
                            <td><Input v-model.number="l.qty_ordered" type="number" min="1" class="h-8" required /></td>
                            <td><Input v-model="l.unit_cost" type="number" min="0" step="0.0001" class="h-8" required /></td>
                            <td><Input v-model="l.tax_rate" type="number" min="0" max="100" step="0.01" class="h-8" /></td>
                            <td class="text-right tabular-nums">${{ lineTotal(l).toFixed(2) }}</td>
                            <td class="text-right"><Button type="button" variant="ghost" size="sm" @click="form.lines.splice(i, 1)">Remove</Button></td>
                        </tr>
                        <tr v-if="!form.lines.length"><td colspan="7" class="py-6 text-center text-muted-foreground">Add products above.</td></tr>
                    </tbody>
                    <tfoot class="text-right">
                        <tr><td colspan="5" class="pt-2">Subtotal</td><td class="pt-2 tabular-nums">${{ subtotal.toFixed(2) }}</td><td /></tr>
                        <tr><td colspan="5">GST</td><td class="tabular-nums">${{ gst.toFixed(2) }}</td><td /></tr>
                        <tr class="font-semibold"><td colspan="5">Total (estimate; recalculated on save)</td><td class="tabular-nums">${{ (subtotal + gst).toFixed(2) }}</td><td /></tr>
                    </tfoot>
                </table>
                <InputError :message="form.errors.lines || errors.po" />
            </section>

            <div class="grid max-w-3xl gap-1"><Label>Notes (printed on the PO)</Label><textarea v-model="form.notes" rows="3" class="rounded-md border bg-background p-2 text-sm" /></div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || !form.lines.length">{{ po ? 'Save draft' : 'Create draft' }}</Button>
                <Button variant="ghost" as-child><Link :href="po ? route('purchase-orders.show', po.id) : route('purchase-orders.index')">Cancel</Link></Button>
            </div>
        </form>
    </AppLayout>
</template>
