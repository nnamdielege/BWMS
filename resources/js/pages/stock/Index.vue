<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Row {
    id: number;
    sku: string;
    name: string;
    base_unit: string;
    is_batch_tracked: boolean;
    reorder_point: number | null;
    on_hand: number;
    sellable: number;
    reserved: number;
    available: number;
}

const props = defineProps<{ products: Paginated<Row>; filters: { q?: string } }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stock on hand', href: '/stock' }];
const q = ref(props.filters.q ?? '');
const search = () => router.get(route('stock.index'), { q: q.value }, { preserveState: true, replace: true });
const low = (r: Row) => r.reorder_point !== null && r.sellable <= r.reorder_point;
</script>

<template>
    <Head title="Stock on hand" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex gap-2" @submit.prevent="search">
                    <Input v-model="q" placeholder="SKU, name or scan barcode" class="w-72" autofocus />
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <Link :href="route('stock.movements')" class="ml-auto text-sm underline">Movement history</Link>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">SKU</th><th>Name</th><th class="text-right">On hand</th>
                        <th class="text-right" title="Sellable bins, excluding expired batches and quarantine">Sellable</th>
                        <th class="text-right" title="On open orders, not yet dispatched">Reserved</th>
                        <th class="text-right" title="What Ordermentum and the portal see">Available</th><th class="pl-3">Unit</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in products.data" :key="r.id" class="border-b">
                        <td class="py-2 font-mono">{{ r.sku }}</td>
                        <td>{{ r.name }}</td>
                        <td class="text-right tabular-nums" :class="{ 'text-red-600': r.on_hand < 0 }">{{ r.on_hand }}</td>
                        <td class="text-right tabular-nums" :class="{ 'font-semibold text-amber-600': low(r) }">{{ r.sellable }}</td>
                        <td class="text-right tabular-nums">{{ r.reserved }}</td>
                        <td class="text-right tabular-nums font-semibold" :class="{ 'text-red-600': r.available < 0 }" title="What Ordermentum and the portal see">{{ r.available }}</td>
                        <td class="pl-3">{{ r.base_unit }}</td>
                        <td class="text-right"><Link :href="route('stock.show', r.id)" class="underline">Detail</Link></td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="8" class="py-6 text-center text-muted-foreground">No products found.</td></tr>
                </tbody>
            </table>

            <Pagination :links="products.links" />
        </div>
    </AppLayout>
</template>
