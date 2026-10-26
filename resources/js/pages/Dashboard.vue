<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{
    widgets: {
        oversold?: { id: number; number: string; channel: string; oversold: boolean; needs_review: boolean }[];
        awaiting_pick?: number;
        overdue_pos?: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];
const page = usePage<SharedData>();
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
        </div>
    </AppLayout>
</template>
