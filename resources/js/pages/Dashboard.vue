<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

defineProps<{
    widgets: {
        oversold?: { id: number; number: string; channel: string; oversold: boolean; needs_review: boolean }[];
        awaiting_pick?: number;
        overdue_pos?: number;
        awaiting_putaway?: number;
        sync_failures_24h?: number;
        negative_bins?: { total: number; rows: { product_id: number; sku: string; name: string; on_hand: number; bin: string }[] };
        count_tasks?: {
            total: number;
            can_manage: boolean;
            rows: {
                id: number;
                location_id: number;
                bin: string;
                sku: string | null;
                on_hand_at_raise: number | null;
                times_raised: number;
                raised_at: string;
            }[];
        };
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];
const page = usePage<SharedData>();

const startCount = (locationId: number) =>
    router.post(route('stocktakes.store'), { scope_location_id: locationId, note: 'Count task: bin went negative' });
const dismiss = (id: number) => {
    const note = window.prompt('Why is no count needed?');
    if (note) router.post(route('count-tasks.dismiss', id), { note }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Welcome, {{ page.props.auth.user?.name }}</h1>

            <div class="grid gap-4 md:grid-cols-3">
                <Link v-if="widgets.awaiting_pick !== undefined" :href="route('orders.index')" class="rounded-lg border p-4 hover:bg-muted">
                    <div class="text-sm text-muted-foreground">Orders awaiting pick</div>
                    <div class="text-3xl font-semibold tabular-nums">{{ widgets.awaiting_pick }}</div>
                </Link>
                <Link v-if="widgets.overdue_pos !== undefined" :href="route('purchase-orders.index')" class="rounded-lg border p-4 hover:bg-muted">
                    <div class="text-sm text-muted-foreground">Overdue purchase orders</div>
                    <div class="text-3xl font-semibold tabular-nums" :class="{ 'text-red-600': widgets.overdue_pos }">{{ widgets.overdue_pos }}</div>
                </Link>
                <Link v-if="widgets.sync_failures_24h !== undefined" :href="route('sync.index')" class="rounded-lg border p-4 hover:bg-muted">
                    <div class="text-sm text-muted-foreground">Sync failures (last 24 h)</div>
                    <div class="text-3xl font-semibold tabular-nums" :class="{ 'text-red-600': widgets.sync_failures_24h }">{{ widgets.sync_failures_24h }}</div>
                </Link>
                <Link v-if="widgets.awaiting_putaway" :href="route('stock.index')" class="rounded-lg border p-4 hover:bg-muted">
                    <div class="text-sm text-muted-foreground">Stock lines awaiting put-away</div>
                    <div class="text-3xl font-semibold tabular-nums">{{ widgets.awaiting_putaway }}</div>
                </Link>
            </div>

            <section v-if="widgets.oversold?.length" class="rounded-lg border border-red-300 p-4">
                <h2 class="mb-2 font-semibold text-red-700">Orders needing attention</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="o in widgets.oversold" :key="o.id">
                        <Link :href="route('orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                        <span class="ml-2 capitalize text-muted-foreground">{{ o.channel }}</span>
                        <span v-if="o.oversold" class="ml-2 rounded bg-red-100 px-1.5 text-xs text-red-800">oversold</span>
                        <span v-if="o.needs_review" class="ml-2 rounded bg-amber-100 px-1.5 text-xs text-amber-900">review</span>
                    </li>
                </ul>
            </section>

            <section v-if="widgets.count_tasks?.total" class="rounded-lg border border-amber-300 p-4">
                <h2 class="mb-2 font-semibold text-amber-800">Bins to count ({{ widgets.count_tasks.total }})</h2>
                <table class="w-full text-left text-sm">
                    <thead class="text-muted-foreground">
                        <tr>
                            <th class="py-1">Bin</th>
                            <th>Product</th>
                            <th class="text-right">On hand when raised</th>
                            <th class="text-right">Times</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in widgets.count_tasks.rows" :key="t.id" class="border-t">
                            <td class="py-1 font-mono">{{ t.bin }}</td>
                            <td class="font-mono">{{ t.sku ?? '—' }}</td>
                            <td class="text-right tabular-nums text-red-700">{{ t.on_hand_at_raise }}</td>
                            <td class="text-right tabular-nums">{{ t.times_raised }}</td>
                            <td class="space-x-1 text-right">
                                <template v-if="widgets.count_tasks.can_manage">
                                    <Button size="sm" variant="outline" @click="startCount(t.location_id)">Start count</Button>
                                    <Button size="sm" variant="ghost" @click="dismiss(t.id)">Dismiss</Button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="widgets.negative_bins?.total" class="rounded-lg border border-red-300 p-4">
                <h2 class="mb-2 font-semibold text-red-700">Negative bins ({{ widgets.negative_bins.total }})</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="(b, i) in widgets.negative_bins.rows" :key="i">
                        <Link :href="route('stock.show', b.product_id)" class="font-mono underline">{{ b.sku }}</Link>
                        <span class="ml-2">{{ b.name }}</span>
                        <span class="ml-2 font-mono text-muted-foreground">{{ b.bin }}</span>
                        <span class="ml-2 tabular-nums text-red-700">{{ b.on_hand }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
