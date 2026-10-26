<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type UnitInput = {
    id?: number;
    name: string;
    code: string | null;
    base_qty: number;
    purchasable: boolean;
    sellable: boolean;
    default_purchase: boolean;
    default_sale: boolean;
    barcodes: string[];
};

interface ProductData {
    id: number;
    sku: string;
    name: string;
    description: string | null;
    category_id: number | null;
    range_id: number | null;
    base_unit: string;
    is_batch_tracked: boolean;
    shelf_life_days: number | null;
    short_dated_days: number | null;
    reorder_point: number | null;
    reorder_qty: number | null;
    sell_price: string | null;
    is_active: boolean;
    units: (Omit<UnitInput, 'barcodes'> & { barcodes: { barcode: string }[] })[];
}

const props = defineProps<{
    product: ProductData | null;
    categories: { id: number; name: string }[];
    ranges: { id: number; name: string }[];
}>();

const can = useCan();
const readOnly = computed(() => !can('products.manage'));
const isNew = !props.product;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: props.product ? props.product.sku : 'New product', href: '#' },
];

const p = props.product;
const form = useForm({
    sku: p?.sku ?? '',
    name: p?.name ?? '',
    description: p?.description ?? '',
    category_id: p?.category_id ?? null,
    range_id: p?.range_id ?? null,
    base_unit: p?.base_unit ?? 'each',
    is_batch_tracked: p?.is_batch_tracked ?? false,
    shelf_life_days: p?.shelf_life_days ?? null,
    short_dated_days: p?.short_dated_days ?? null,
    reorder_point: p?.reorder_point ?? null,
    reorder_qty: p?.reorder_qty ?? null,
    sell_price: p?.sell_price ?? null,
    is_active: p?.is_active ?? true,
    units: (p?.units.map((u) => ({ ...u, barcodes: u.barcodes.map((b) => b.barcode) })) ?? [
        { name: 'Each', code: null, base_qty: 1, purchasable: true, sellable: true, default_purchase: true, default_sale: true, barcodes: [] },
    ]) as UnitInput[],
});

const addRange = () => {
    const name = prompt('New range name (e.g. "Dash syrups")')?.trim();
    if (name) router.post(route('ranges.store'), { name }, { preserveState: true, preserveScroll: true, only: ['ranges', 'flash', 'errors'] });
};

const addUnit = () =>
    form.units.push({ name: 'Carton', code: null, base_qty: 12, purchasable: true, sellable: true, default_purchase: false, default_sale: false, barcodes: [] });
const removeUnit = (i: number) => form.units.splice(i, 1);
const addBarcode = (u: UnitInput) => u.barcodes.push('');
const setDefault = (field: 'default_purchase' | 'default_sale', i: number) => form.units.forEach((u, j) => (u[field] = i === j));

const submit = () => (isNew ? form.post(route('products.store')) : form.put(route('products.update', p!.id), { preserveScroll: true }));
const unitErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([k]) => k.startsWith('units'))
        .map(([, v]) => v),
);
</script>

<template>
    <Head :title="product ? product.sku : 'New product'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="max-w-4xl space-y-6 p-4" @submit.prevent="submit">
            <fieldset :disabled="readOnly" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="sku">SKU</Label>
                    <Input id="sku" v-model="form.sku" class="font-mono uppercase" required />
                    <InputError :message="form.errors.sku" />
                </div>
                <div class="grid gap-1">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1">
                    <Label for="category">Category</Label>
                    <select id="category" v-model="form.category_id" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option :value="null">—</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1">
                    <Label for="range">Range (optional, groups flavours in lists)</Label>
                    <div class="flex gap-2">
                        <select id="range" v-model="form.range_id" class="h-9 flex-1 rounded-md border bg-background px-2 text-sm">
                            <option :value="null">—</option>
                            <option v-for="r in ranges" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <Button v-if="!readOnly" type="button" variant="outline" size="sm" @click="addRange">New</Button>
                    </div>
                </div>
                <div class="grid gap-1">
                    <Label for="base_unit">Base unit (what one stock unit is)</Label>
                    <Input id="base_unit" v-model="form.base_unit" placeholder="each, bottle, carton…" required />
                    <InputError :message="form.errors.base_unit" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="description">Description</Label>
                    <textarea id="description" v-model="form.description" rows="2" class="rounded-md border bg-background p-2 text-sm" />
                </div>
                <div class="grid gap-1">
                    <Label for="reorder_point">Reorder point (base units)</Label>
                    <Input id="reorder_point" v-model.number="form.reorder_point" type="number" min="0" />
                </div>
                <div class="grid gap-1">
                    <Label for="reorder_qty">Reorder quantity (base units)</Label>
                    <Input id="reorder_qty" v-model.number="form.reorder_qty" type="number" min="0" />
                </div>
                <div class="grid gap-1">
                    <Label for="sell_price">Default sell price</Label>
                    <Input id="sell_price" v-model="form.sell_price" type="number" min="0" step="0.01" />
                </div>
                <div class="flex items-end gap-6">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" /> Active</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_batch_tracked" /> Track batches &amp; best-before</label>
                </div>
                <template v-if="form.is_batch_tracked">
                    <div class="grid gap-1">
                        <Label for="shelf_life_days">Typical shelf life (days)</Label>
                        <Input id="shelf_life_days" v-model.number="form.shelf_life_days" type="number" min="1" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="short_dated_days">Flag as short-dated within (days)</Label>
                        <Input id="short_dated_days" v-model.number="form.short_dated_days" type="number" min="0" />
                    </div>
                </template>
            </fieldset>

            <section>
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Pack sizes &amp; barcodes</h2>
                    <Button v-if="!readOnly" type="button" variant="outline" size="sm" @click="addUnit">Add pack size</Button>
                </div>
                <p class="mb-2 text-xs text-muted-foreground">
                    Quantity is how many base units ({{ form.base_unit }}) are in the pack. Exactly one pack size has quantity 1. A saved pack's quantity cannot be changed.
                </p>
                <InputError v-for="(e, i) in unitErrors" :key="i" :message="e" />

                <fieldset :disabled="readOnly" class="space-y-3">
                    <div v-for="(u, i) in form.units" :key="u.id ?? `new-${i}`" class="grid gap-2 rounded-md border p-3 sm:grid-cols-6">
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Name</Label>
                            <Input v-model="u.name" required />
                        </div>
                        <div class="grid gap-1">
                            <Label>Qty in base units</Label>
                            <Input v-model.number="u.base_qty" type="number" min="1" :disabled="!!u.id" required />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Pack code (optional)</Label>
                            <Input v-model="u.code" class="font-mono" />
                        </div>
                        <div class="flex items-end justify-end">
                            <Button v-if="!readOnly && u.base_qty !== 1" type="button" variant="ghost" size="sm" @click="removeUnit(i)">Remove</Button>
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm sm:col-span-6">
                            <label class="flex items-center gap-1"><input type="checkbox" v-model="u.purchasable" /> Purchasable</label>
                            <label class="flex items-center gap-1"><input type="checkbox" v-model="u.sellable" /> Sellable</label>
                            <label class="flex items-center gap-1"><input type="radio" :checked="u.default_purchase" @change="setDefault('default_purchase', i)" /> Default for POs</label>
                            <label class="flex items-center gap-1"><input type="radio" :checked="u.default_sale" @change="setDefault('default_sale', i)" /> Default for sales</label>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 sm:col-span-6">
                            <span class="text-sm text-muted-foreground">Barcodes:</span>
                            <Input v-for="(_, b) in u.barcodes" :key="b" v-model="u.barcodes[b]" class="w-44 font-mono" placeholder="Scan or type" />
                            <Button v-if="!readOnly" type="button" variant="ghost" size="sm" @click="addBarcode(u)">+ barcode</Button>
                        </div>
                    </div>
                </fieldset>
            </section>

            <div class="flex gap-2">
                <Button v-if="!readOnly" type="submit" :disabled="form.processing">{{ isNew ? 'Create product' : 'Save' }}</Button>
                <Button variant="ghost" as-child><Link :href="route('products.index')">Back</Link></Button>
            </div>
        </form>
    </AppLayout>
</template>
