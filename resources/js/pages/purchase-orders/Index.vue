<script setup lang="ts">
import BudgetBar from '@/components/BudgetBar.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type BudgetSummary, type Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface Row {
    id: number;
    number: string;
    email_error: string | null;
    status: string;
    status_label: string;
    supplier: string;
    site: string;
    order_date: string;
    expected_date: string | null;
    overdue: boolean;
    payment_due_date: string | null;
    over_budget: boolean;
    paid: boolean;
    total: string;
    legacy: boolean;
}

const props = defineProps<{
    orders: Paginated<Row>;
    filters: { q: string; status: string; supplier_id: number | null };
    suppliers: { id: number; name: string }[];
    statuses: { value: string; label: string }[];
    canManage: boolean;
    budget: BudgetSummary;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Purchase orders', href: '/purchase-orders' }];
const form = reactive({ ...props.filters });
const search = () => router.get(route('purchase-orders.index'), { ...form }, { preserveState: true, replace: true });
const generating = ref(false);
const generate = () =>
    router.post(
        route('purchase-orders.suggest'),
        {},
        { preserveScroll: true, onStart: () => (generating.value = true), onFinish: () => (generating.value = false) },
    );
const errors = computed(() => usePage().props.errors as Record<string, string>);
const badge: Record<string, string> = {
    suggested: 'bg-purple-100 text-purple-900',
    draft: 'bg-muted',
    approved: 'bg-sky-100 text-sky-900',
    sent: 'bg-blue-100 text-blue-900',
    partially_received: 'bg-amber-100 text-amber-900',
    received: 'bg-green-100 text-green-900',
    paid: 'bg-emerald-200 text-emerald-900',
    closed: 'bg-slate-200 text-slate-800',
    cancelled: 'bg-red-100 text-red-900',
};
</script>

<template>
    <Head title="Purchase orders" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex flex-wrap gap-2" @submit.prevent="search">
                    <Input v-model="form.q" placeholder="PO number" class="w-44 font-mono" />
                    <select v-model="form.status" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option value="open">Open</option>
                        <option value="all">All</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <select v-model="form.supplier_id" class="h-9 rounded-md border bg-background px-2 text-sm" @change="search">
                        <option :value="null">All suppliers</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <Button type="submit" variant="outline">Search</Button>
                </form>
                <div v-if="canManage" class="ml-auto flex gap-2">
                    <Button
                        variant="outline"
                        :disabled="generating"
                        title="One suggested PO per supplier from sales velocity; also runs Mondays 6am"
                        @click="generate"
                    >
                        {{ generating ? 'Generating…' : 'Generate suggestions' }}
                    </Button>
                    <Button as-child><Link :href="route('purchase-orders.create')">New purchase order</Link></Button>
                </div>
            </div>
            <InputError :message="errors.po" />
            <BudgetBar :budget="budget" />

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Number</th>
                        <th>Supplier</th>
                        <th>Deliver to</th>
                        <th>Ordered</th>
                        <th>Expected</th>
                        <th>Payment due</th>
                        <th>Status</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-b">
                        <td class="py-2">
                            <Link :href="route('purchase-orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                        </td>
                        <td>{{ o.supplier }}</td>
                        <td>{{ o.site }}</td>
                        <td>{{ o.order_date }}</td>
                        <td :class="{ 'font-semibold text-red-600': o.overdue }">{{ o.expected_date ?? '' }}{{ o.overdue ? ' (overdue)' : '' }}</td>
                        <td>{{ o.payment_due_date ?? '' }}</td>
                        <td>
                            <span class="rounded px-2 py-0.5 text-xs" :class="badge[o.status]">{{ o.status_label }}</span>
                            <span
                                v-if="o.paid && o.status !== 'paid'"
                                class="ml-1 text-xs font-semibold text-emerald-700"
                                title="Paid before it was fully received"
                                >paid</span
                            >
                            <span v-if="o.over_budget" class="ml-1 text-xs font-semibold text-amber-700" title="Approved over budget"
                                >over budget</span
                            >
                            <span v-if="o.legacy" class="ml-1 text-xs text-muted-foreground">legacy</span>
                            <span v-if="o.email_error" class="ml-1 text-xs font-semibold text-red-600" :title="o.email_error">email failed</span>
                        </td>
                        <td class="text-right tabular-nums">${{ Number(o.total).toFixed(2) }}</td>
                    </tr>
                    <tr v-if="!orders.data.length">
                        <td colspan="8" class="py-6 text-center text-muted-foreground">No purchase orders.</td>
                    </tr>
                </tbody>
            </table>
            <Pagination :links="orders.links" />
        </div>
    </AppLayout>
</template>
