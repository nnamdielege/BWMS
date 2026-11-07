<script setup lang="ts">
import HeldBadge, { type Hold } from '@/components/HeldBadge.vue';
import InputError from '@/components/InputError.vue';
import MovedBadge from '@/components/MovedBadge.vue';
import Pagination from '@/components/Pagination.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { dayGroups } from '@/lib/deliveryDays';
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
    delivery_date: string | null;
    moved_from: string | null;
    hold: Hold | null;
    run: string | null;
    review_notes: string[];
    created_at: string;
}

const props = defineProps<{
    orders: Paginated<Row>;
    filters: { q: string; status: string; channel: string; flag: string; delivery_date: string };
    today: string;
    statuses: { value: string; label: string }[];
    can: { manage: boolean; pick: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Orders', href: '/orders' }];
const form = reactive({ ...props.filters });
const search = () => router.get(route('orders.index'), { ...form }, { preserveState: true, replace: true });

// Pick-list creation from selected orders (one site per pick list).
const selected = ref<number[]>([]);
const pickable = (o: Row) => ['open', 'partially_dispatched'].includes(o.status) && !o.hold;
const selectedRows = computed(() => props.orders.data.filter((o) => selected.value.includes(o.id)));
const siteConflict = computed(() => new Set(selectedRows.value.map((o) => o.site_id)).size > 1);
const createPickList = () =>
    router.post(route('pick-lists.store'), { site_id: selectedRows.value[0].site_id, order_ids: selected.value }, { onSuccess: () => (selected.value = []) });
const errors = computed(() => usePage().props.errors as Record<string, string>);
const groups = computed(() => dayGroups(props.orders.data, (o) => o.delivery_date, props.today, (o) => ['open', 'picking', 'partially_dispatched'].includes(o.status)));
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
                        <option value="">All sources</option>
                        <option value="manual">Manual</option>
                        <option value="portal">Portal</option>
                        <option value="ordermentum">Ordermentum</option>
                    </select>
                    <select v-model="form.flag" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="">Any flag</option>
                        <option value="oversold">Oversold</option>
                        <option value="review">Needs review</option>
                    </select>
                    <Input v-model="form.delivery_date" type="date" class="w-40" aria-label="Delivery date" @change="search" />
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <div class="ml-auto flex gap-2">
                    <Button v-if="can.pick && selected.length" :disabled="siteConflict" @click="createPickList">Create pick list ({{ selected.length }})</Button>
                    <Button v-if="can.manage" as-child variant="outline"><Link :href="route('orders.consolidate.index')">Consolidate</Link></Button>
                    <Button v-if="can.manage" as-child variant="outline"><Link :href="route('orders.create')">New order</Link></Button>
                </div>
            </div>
            <p v-if="siteConflict" class="text-sm text-red-600">Selected orders are for different sites; a pick list covers one site.</p>
            <InputError :message="errors.pick" />

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th v-if="can.pick" class="w-8"></th><th class="py-2">Order</th><th>Source</th><th>Customer</th><th>Site</th><th>Run</th><th>Status</th><th>Flags</th></tr>
                </thead>
                <tbody v-for="group in groups" :key="group.key">
                    <tr>
                        <th :colspan="can.pick ? 8 : 7" class="pt-4 pb-1 text-xs font-semibold tracking-wide uppercase" :class="group.overdue ? 'text-red-700 dark:text-red-300' : 'text-muted-foreground'">
                            {{ group.label }}
                        </th>
                    </tr>
                    <tr v-for="o in group.items" :key="o.id" class="border-b">
                        <td v-if="can.pick"><input v-if="pickable(o)" type="checkbox" :value="o.id" v-model="selected" /></td>
                        <td class="py-2">
                            <Link :href="route('orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                            <div v-if="o.external_ref" class="text-xs text-muted-foreground">{{ o.external_ref }}</div>
                            <MovedBadge :from="o.moved_from" />
                            <HeldBadge :hold="o.hold" />
                        </td>
                        <td><SourceBadge :channel="o.channel" /></td>
                        <td>{{ o.customer ?? '—' }}</td>
                        <td>{{ o.site }}</td>
                        <td>{{ o.run ?? '' }}<span v-if="group.overdue" class="text-muted-foreground"> {{ o.delivery_date }}</span></td>
                        <td>{{ o.status_label }}</td>
                        <td class="space-x-1">
                            <span v-if="o.oversold" class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">oversold</span>
                            <span v-if="o.needs_review" class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900" :title="o.review_notes.join(' ')">review</span>
                        </td>
                    </tr>
                </tbody>
                <tbody v-if="!orders.data.length">
                    <tr><td :colspan="can.pick ? 8 : 7" class="py-6 text-center text-muted-foreground">No orders.</td></tr>
                </tbody>
            </table>
            <Pagination :links="orders.links" />
        </div>
    </AppLayout>
</template>
