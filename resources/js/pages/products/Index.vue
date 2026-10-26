<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface ProductRow {
    id: number;
    sku: string;
    name: string;
    base_unit: string;
    is_active: boolean;
    is_batch_tracked: boolean;
    category: { id: number; name: string } | null;
    units: { id: number; name: string; base_qty: number }[];
}

const props = defineProps<{
    products: Paginated<ProductRow>;
    filters: { q?: string; status?: string };
    canManage: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Products', href: '/products' }];
const form = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? 'active' });
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
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <Button v-if="canManage" as-child class="ml-auto">
                    <Link :href="route('products.create')">New product</Link>
                </Button>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">SKU</th><th>Name</th><th>Category</th><th>Base unit</th><th>Pack sizes</th><th>Batches</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id" class="border-b" :class="{ 'text-muted-foreground': !p.is_active }">
                        <td class="py-2 font-mono">{{ p.sku }}</td>
                        <td>{{ p.name }}</td>
                        <td>{{ p.category?.name ?? '—' }}</td>
                        <td>{{ p.base_unit }}</td>
                        <td>{{ packs(p) || '—' }}</td>
                        <td>{{ p.is_batch_tracked ? 'Tracked' : '' }}</td>
                        <td class="text-right">
                            <Link :href="route('products.edit', p.id)" class="underline">{{ canManage ? 'Edit' : 'View' }}</Link>
                        </td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="7" class="py-6 text-center text-muted-foreground">No products found.</td></tr>
                </tbody>
            </table>

            <Pagination :links="products.links" />
        </div>
    </AppLayout>
</template>
