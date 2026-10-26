<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface Row {
    id: number;
    number: string;
    channel: string;
    external_ref: string | null;
    customer: string | null;
    site: string;
    site_id: number;
    status: string;
    status_label: string;
    oversold: boolean;
    needs_review: boolean;
    requested_delivery_date: string | null;
    created_at: string;
}

const props = defineProps<{
    orders: Paginated<Row>;
    filters: { q: string; status: string; channel: string; flag: string };
    statuses: { value: string; label: string }[];
    can: { manage: boolean; pick: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Orders', href: '/orders' }];
const form = reactive({ ...props.filters });
const search = () => router.get(route('orders.index'), { ...form }, { preserveState: true, replace: true });

// Pick-list creation from selected orders (one site per pick list).
const selected = ref<number[]>([]);
const pickable = (o: Row) => ['open', 'partially_dispatched'].includes(o.status);
const selectedRows = computed(() => props.orders.data.filter((o) => selected.value.includes(o.id)));
const siteConflict = computed(() => new Set(selectedRows.value.map((o) => o.site_id)).size > 1);
const createPickList = () =>
    router.post(route('pick-lists.store'), { site_id: selectedRows.value[0].site_id, order_ids: selected.value }, { onSuccess: () => (selected.value = []) });
const errors = computed(() => usePage().props.errors as Record<string, string>);
</script>

<template>
    <Head title="Orders" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex flex-wrap gap-2" @submit.prevent="search">
                    <Input v-model="form.q" placeholder="Order or channel ref" class="w-48" />
                    <select v-model="form.status" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="active">Active</option>
                        <option value="all">All</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <select v-model="form.channel" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="">All channels</option>
                        <option value="manual">Manual</option>
                        <option value="portal">Portal</option>
                        <option value="ordermentum">Ordermentum</option>
                    </select>
                    <select v-model="form.flag" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="">Any flag</option>
                        <option value="oversold">Oversold</option>
                        <option value="review">Needs review</option>
                    </select>
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <div class="ml-auto flex gap-2">
                    <Button v-if="can.pick && selected.length" :disabled="siteConflict" @click="createPickList">Create pick list ({{ selected.length }})</Button>
                    <Button v-if="can.manage" as-child variant="outline"><Link :href="route('orders.create')">New order</Link></Button>
                </div>
            </div>
            <p v-if="siteConflict" class="text-sm text-red-600">Selected orders are for different sites; a pick list covers one site.</p>
            <InputError :message="errors.pick" />

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th v-if="can.pick" class="w-8"></th><th class="py-2">Order</th><th>Channel</th><th>Customer</th><th>Site</th><th>Deliver</th><th>Status</th><th>Flags</th></tr>
                </thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-b">
                        <td v-if="can.pick"><input v-if="pickable(o)" type="checkbox" :value="o.id" v-model="selected" /></td>
                        <td class="py-2">
                            <Link :href="route('orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                            <div v-if="o.external_ref" class="text-xs text-muted-foreground">{{ o.external_ref }}</div>
                        </td>
                        <td class="capitalize">{{ o.channel }}</td>
                        <td>{{ o.customer ?? '—' }}</td>
                        <td>{{ o.site }}</td>
                        <td>{{ o.requested_delivery_date ?? '' }}</td>
                        <td>{{ o.status_label }}</td>
                        <td class="space-x-1">
                            <span v-if="o.oversold" class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">oversold</span>
                            <span v-if="o.needs_review" class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">review</span>
                        </td>
                    </tr>
                    <tr v-if="!orders.data.length"><td :colspan="can.pick ? 8 : 7" class="py-6 text-center text-muted-foreground">No orders.</td></tr>
                </tbody>
            </table>
            <Pagination :links="orders.links" />
        </div>
    </AppLayout>
</template>
