<script setup lang="ts">
import MovementTable, { type MovementRow } from '@/components/MovementTable.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps<{
    movements: Paginated<MovementRow>;
    filters: { sku?: string; type?: string; from?: string; to?: string };
    types: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock on hand', href: '/stock' },
    { title: 'Movements', href: '/stock/movements' },
];
const form = reactive({ sku: props.filters.sku ?? '', type: props.filters.type ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const apply = () => router.get(route('stock.movements'), { ...form }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Stock movements" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <form class="flex flex-wrap gap-2" @submit.prevent="apply">
                <Input v-model="form.sku" placeholder="SKU" class="w-40 font-mono" />
                <select v-model="form.type" class="h-9 rounded-md border bg-background px-2 text-sm">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <Input v-model="form.from" type="date" class="w-40" />
                <Input v-model="form.to" type="date" class="w-40" />
                <Button type="submit" variant="outline">Filter</Button>
            </form>

            <MovementTable :movements="movements.data" show-product />
            <Pagination :links="movements.links" />
        </div>
    </AppLayout>
</template>
