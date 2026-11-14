<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dayGroups } from '@/lib/deliveryDays';
import { useAutoRefresh } from '@/lib/useAutoRefresh';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Row {
    id: number;
    number: string;
    site: string;
    status: string;
    stage: { key: string; label: string };
    delivery_date: string | null;
    run: string | null;
    lines: number;
    created_by: string;
    created_at: string;
    oversold_skus?: string[];
}

const props = defineProps<{ view: 'open' | 'history'; open: Row[]; history: Paginated<Row> | null; today: string }>();
useAutoRefresh(['open', 'history']);
const groups = computed(() => dayGroups(props.open, (p) => p.delivery_date, props.today));
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pick lists (desktop)', href: '/pick-lists' }];
const fmt = (iso: string) => new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' });
</script>

<template>
    <Head title="Pick lists (desktop)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <p class="text-sm text-muted-foreground">
                Create a pick list by selecting orders on the <Link :href="route('orders.index')" class="underline">Orders</Link> page.
            </p>
            <nav class="flex gap-4 border-b text-sm" aria-label="Pick list views">
                <Link :href="route('pick-lists.index')" class="-mb-px border-b-2 px-1 py-2" :class="view === 'open' ? 'border-primary font-semibold' : 'border-transparent text-muted-foreground'">Open</Link>
                <Link :href="route('pick-lists.index', { view: 'history' })" class="-mb-px border-b-2 px-1 py-2" :class="view === 'history' ? 'border-primary font-semibold' : 'border-transparent text-muted-foreground'">History</Link>
            </nav>

            <p v-if="view === 'open' && !groups.length" class="py-6 text-center text-sm text-muted-foreground">No open pick lists.</p>
            <section v-for="group in groups" :key="group.key">
                <h2 class="mb-1 text-xs font-semibold tracking-wide uppercase" :class="group.overdue ? 'text-red-700 dark:text-red-300' : 'text-muted-foreground'">{{ group.label }}</h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="p in group.items" :key="p.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 p-2">
                        <Link :href="route('pick-lists.show', p.id)" class="font-mono underline">{{ p.number }}</Link>
                        <span>{{ p.run ?? p.site }}</span>
                        <span v-if="group.overdue" class="text-muted-foreground">{{ p.delivery_date }}</span>
                        <span class="rounded bg-muted px-2 py-0.5 text-xs">{{ p.stage.label }}</span>
                        <span class="text-muted-foreground">{{ p.lines }} line(s) · {{ p.created_by }}</span>
                        <span v-if="p.oversold_skus?.length" class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-950 dark:text-red-200">Oversold: {{ p.oversold_skus.join(', ') }}</span>
                    </li>
                </ul>
            </section>

            <template v-if="history">
            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">Number</th><th>Site</th><th>Status</th><th>Delivery</th><th class="text-right">Lines</th><th>Created</th><th>By</th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in history.data" :key="p.id" class="border-b">
                        <td class="py-2"><Link :href="route('pick-lists.show', p.id)" class="font-mono underline">{{ p.number }}</Link></td>
                        <td>{{ p.site }}</td>
                        <td>{{ p.stage.label }}</td>
                        <td>{{ p.delivery_date ?? '' }}</td>
                        <td class="text-right">{{ p.lines }}</td>
                        <td>{{ fmt(p.created_at) }}</td>
                        <td>{{ p.created_by }}</td>
                    </tr>
                    <tr v-if="!history.data.length"><td colspan="7" class="py-6 text-center text-muted-foreground">None yet.</td></tr>
                </tbody>
            </table>
            <Pagination :links="history.links" />
            </template>
        </div>
    </AppLayout>
</template>
