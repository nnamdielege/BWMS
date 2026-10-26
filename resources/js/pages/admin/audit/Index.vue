<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface LogRow {
    id: number;
    created_at: string;
    user: string | null;
    event: string;
    subject: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    meta: Record<string, unknown> | null;
    ip_address: string | null;
}

const props = defineProps<{
    logs: Paginated<LogRow>;
    filters: { event?: string; subject?: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/admin/audit-log' }];
const form = reactive({ event: props.filters.event ?? '', subject: props.filters.subject ?? '' });
const apply = () => router.get(route('admin.audit.index'), { ...form }, { preserveState: true, replace: true });
const fmt = (iso: string) => new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' });
const json = (v: unknown) => (v ? JSON.stringify(v, null, 1) : '');
</script>

<template>
    <Head title="Audit log" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <form class="flex flex-wrap gap-2" @submit.prevent="apply">
                <Input v-model="form.event" placeholder="Event (e.g. roles_changed)" class="w-56" />
                <Input v-model="form.subject" placeholder="Subject type (e.g. User)" class="w-56" />
                <Button type="submit" variant="outline">Filter</Button>
            </form>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">When (Brisbane)</th><th>User</th><th>Event</th><th>Subject</th><th>Before</th><th>After</th><th>IP</th></tr>
                </thead>
                <tbody>
                    <tr v-for="log in logs.data" :key="log.id" class="border-b align-top">
                        <td class="whitespace-nowrap py-2">{{ fmt(log.created_at) }}</td>
                        <td>{{ log.user ?? 'system' }}</td>
                        <td>{{ log.event }}</td>
                        <td>{{ log.subject }}</td>
                        <td><pre class="max-w-xs overflow-x-auto text-xs">{{ json(log.old_values) }}</pre></td>
                        <td><pre class="max-w-xs overflow-x-auto text-xs">{{ json(log.new_values ?? log.meta) }}</pre></td>
                        <td>{{ log.ip_address }}</td>
                    </tr>
                </tbody>
            </table>

            <Pagination :links="logs.links" />
        </div>
    </AppLayout>
</template>
