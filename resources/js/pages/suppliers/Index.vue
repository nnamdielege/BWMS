<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Row {
    id: number;
    code: string;
    name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    is_active: boolean;
    open_pos_count: number;
}

const props = defineProps<{ suppliers: Paginated<Row>; filters: { q?: string; status?: string }; canManage: boolean }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Suppliers', href: '/suppliers' }];
const form = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? 'active' });
const search = () => router.get(route('suppliers.index'), { ...form }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Suppliers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex gap-2" @submit.prevent="search">
                    <Input v-model="form.q" placeholder="Name or code" class="w-64" />
                    <select v-model="form.status" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="all">All</option>
                    </select>
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <Button v-if="canManage" as-child class="ml-auto"><Link :href="route('suppliers.create')">New supplier</Link></Button>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">Code</th><th>Name</th><th>Contact</th><th>Email</th><th>Phone</th><th class="text-right">Open POs</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="s in suppliers.data" :key="s.id" class="border-b" :class="{ 'text-muted-foreground': !s.is_active }">
                        <td class="py-2 font-mono">{{ s.code }}</td>
                        <td>{{ s.name }}</td>
                        <td>{{ s.contact_name }}</td>
                        <td>{{ s.email }}</td>
                        <td>{{ s.phone }}</td>
                        <td class="text-right">{{ s.open_pos_count }}</td>
                        <td class="text-right"><Link :href="route('suppliers.edit', s.id)" class="underline">{{ canManage ? 'Edit' : 'View' }}</Link></td>
                    </tr>
                    <tr v-if="!suppliers.data.length"><td colspan="7" class="py-6 text-center text-muted-foreground">No suppliers.</td></tr>
                </tbody>
            </table>
            <Pagination :links="suppliers.links" />
        </div>
    </AppLayout>
</template>
