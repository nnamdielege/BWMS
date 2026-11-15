<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

interface ProductRow {
    id: number;
    sku: string;
    name: string;
    base_unit: string;
    is_active: boolean;
    is_batch_tracked: boolean;
    gst_free: boolean;
    category: { id: number; name: string } | null;
    range: { id: number; name: string } | null;
    units: { id: number; name: string; base_qty: number }[];
}

const props = defineProps<{
    products: Paginated<ProductRow>;
    filters: { q?: string; status?: string; range_id?: string | number; gst?: string; check?: string };
    ranges: { id: number; name: string }[];
    canManage: boolean;
    priceCheck: {
        count: number;
        rows: {
            id: number;
            sku: string;
            name: string;
            pack: string;
            pack_qty: number;
            base_unit: string;
            sell_price: number;
            cost_per_pack: number;
            sell_per_unit: number;
            price_if_per_unit: number;
        }[];
    } | null;
}>();

const money = (v: number, places = 2) => `$${v.toFixed(places)}`;
const checking = computed(() => props.filters.check === 'below_cost');

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Products', href: '/products' }];
const form = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? 'active',
    range_id: props.filters.range_id ?? '',
    gst: props.filters.gst ?? '',
});
const search = () => router.get(route('products.index'), { ...form }, { preserveState: true, replace: true });
const packs = (p: ProductRow) =>
    p.units
        .filter((u) => u.base_qty > 1)
        .map((u) => `${u.name} ×${u.base_qty}`)
        .join(', ');
</script>

<template>
    <Head title="Products" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex flex-wrap gap-2" @submit.prevent="search">
                    <Input v-model="form.q" placeholder="SKU, name or scan barcode" class="w-72" autofocus />
                    <select v-model="form.status" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="all">All</option>
                    </select>
                    <select v-model="form.range_id" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="">All ranges</option>
                        <option v-for="r in ranges" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                    <select v-model="form.gst" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="">GST: all</option>
                        <option value="free">GST-free</option>
                        <option value="taxable">Taxable (10%)</option>
                    </select>
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <Button v-if="canManage" as-child class="ml-auto">
                    <Link :href="route('products.create')">New product</Link>
                </Button>
            </div>

            <div
                v-if="priceCheck && (priceCheck.count || checking)"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                <span>
                    <span class="font-semibold">{{ priceCheck.count }} product{{ priceCheck.count === 1 ? '' : 's' }}</span>
                    {{ priceCheck.count === 1 ? 'sells' : 'sell' }} for less than one sale pack costs. The sell price is per pack (as Ordermentum's
                    base price), so {{ priceCheck.count === 1 ? 'it was' : 'these were' }} probably entered per unit.
                </span>
                <Link v-if="!checking" :href="route('products.index', { check: 'below_cost', status: 'active' })" class="font-semibold underline"
                    >Show them</Link
                >
                <Link v-else :href="route('products.index')" class="font-semibold underline">Show all products</Link>
            </div>

            <table v-if="checking && priceCheck?.rows.length" class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Product</th>
                        <th>Sale pack</th>
                        <th class="text-right">Sell price</th>
                        <th class="text-right">Cost per pack</th>
                        <th class="text-right">Sell per unit</th>
                        <th class="text-right" title="The figure × the pack size, if it was entered per unit">If entered per unit</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in priceCheck.rows" :key="r.id" class="border-b">
                        <td class="py-2">
                            <span class="font-mono">{{ r.sku }}</span> {{ r.name }}
                        </td>
                        <td>{{ r.pack }}{{ r.pack_qty > 1 ? ` (${r.pack_qty})` : '' }}</td>
                        <td class="text-right tabular-nums text-red-600">{{ money(r.sell_price) }}</td>
                        <td class="text-right tabular-nums">{{ money(r.cost_per_pack) }}</td>
                        <td class="text-right tabular-nums text-muted-foreground">{{ money(r.sell_per_unit, 4) }} / {{ r.base_unit }}</td>
                        <td class="text-right tabular-nums">{{ money(r.price_if_per_unit) }}</td>
                        <td class="text-right">
                            <Link :href="route('products.edit', r.id)" class="underline">{{ canManage ? 'Fix' : 'View' }}</Link>
                        </td>
                    </tr>
                </tbody>
            </table>

            <table v-if="!checking" class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">SKU</th>
                        <th>Name</th>
                        <th>Range</th>
                        <th>Category</th>
                        <th>Base unit</th>
                        <th>Pack sizes</th>
                        <th>Batches</th>
                        <th>GST</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id" class="border-b" :class="{ 'text-muted-foreground': !p.is_active }">
                        <td class="py-2 font-mono">{{ p.sku }}</td>
                        <td>{{ p.name }}</td>
                        <td>{{ p.range?.name ?? '' }}</td>
                        <td>{{ p.category?.name ?? '—' }}</td>
                        <td>{{ p.base_unit }}</td>
                        <td>{{ packs(p) || '—' }}</td>
                        <td>{{ p.is_batch_tracked ? 'Tracked' : '' }}</td>
                        <td>{{ p.gst_free ? 'GST-free' : '10%' }}</td>
                        <td class="text-right">
                            <Link :href="route('products.edit', p.id)" class="underline">{{ canManage ? 'Edit' : 'View' }}</Link>
                        </td>
                    </tr>
                    <tr v-if="!products.data.length">
                        <td colspan="9" class="py-6 text-center text-muted-foreground">No products found.</td>
                    </tr>
                </tbody>
            </table>

            <Pagination v-if="!checking" :links="products.links" />
        </div>
    </AppLayout>
</template>
