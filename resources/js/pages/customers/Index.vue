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
    code: string;
    name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    ordermentum_retailer_id: string | null;
    portal_user_id: number | null;
    is_active: boolean;
}

const props = defineProps<{ customers: Paginated<Row>; filters: { q: string | null }; canManage: boolean }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/customers' }];
const q = ref(props.filters.q ?? '');
const search = () => router.get(route('customers.index'), { q: q.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Customers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex gap-2" @submit.prevent="search">
                    <Input v-model="q" placeholder="Name or code" class="w-64" />
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <Button v-if="canManage" as-child class="ml-auto"><Link :href="route('customers.create')">New customer</Link></Button>
            </div>
            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">Code</th><th>Name</th><th>Contact</th><th>Email</th><th>Linked channels</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in customers.data" :key="c.id" class="border-b" :class="{ 'text-muted-foreground': !c.is_active }">
                        <td class="py-2 font-mono">{{ c.code }}</td>
                        <td>{{ c.name }}</td>
                        <td>{{ c.contact_name }}</td>
                        <td>{{ c.email }}</td>
                        <td>{{ [c.ordermentum_retailer_id && 'Ordermentum', c.portal_user_id && 'Portal'].filter(Boolean).join(', ') }}</td>
                        <td class="text-right"><Link :href="route('customers.edit', c.id)" class="underline">{{ canManage ? 'Edit' : 'View' }}</Link></td>
                    </tr>
                    <tr v-if="!customers.data.length"><td colspan="6" class="py-6 text-center text-muted-foreground">No customers.</td></tr>
                </tbody>
            </table>
            <Pagination :links="customers.links" />
        </div>
    </AppLayout>
</template>
