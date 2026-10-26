<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface Row {
    id: number;
    number: string;
    site: string;
    status: string;
    lines: number;
    created_by: string;
    created_at: string;
}

defineProps<{ pickLists: Paginated<Row> }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pick lists', href: '/pick-lists' }];
const fmt = (iso: string) => new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' });
</script>

<template>
    <Head title="Pick lists" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <p class="text-sm text-muted-foreground">
                Create a pick list by selecting orders on the <Link :href="route('orders.index')" class="underline">Orders</Link> page.
            </p>
            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">Number</th><th>Site</th><th>Status</th><th class="text-right">Lines</th><th>Created</th><th>By</th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in pickLists.data" :key="p.id" class="border-b">
                        <td class="py-2"><Link :href="route('pick-lists.show', p.id)" class="font-mono underline">{{ p.number }}</Link></td>
                        <td>{{ p.site }}</td>
                        <td class="capitalize">{{ p.status }}</td>
                        <td class="text-right">{{ p.lines }}</td>
                        <td>{{ fmt(p.created_at) }}</td>
                        <td>{{ p.created_by }}</td>
                    </tr>
                    <tr v-if="!pickLists.data.length"><td colspan="6" class="py-6 text-center text-muted-foreground">No pick lists yet.</td></tr>
                </tbody>
            </table>
            <Pagination :links="pickLists.links" />
        </div>
    </AppLayout>
</template>
