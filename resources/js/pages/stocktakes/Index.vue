<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Row {
    id: number;
    number: string;
    scope: string;
    status: string;
    lines: number;
    created_by: string;
    snapshot_at: string;
}

defineProps<{ stocktakes: Paginated<Row>; scopes: { id: number; label: string }[]; canManage: boolean }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stocktakes', href: '/stocktakes' }];
const form = useForm({ scope_location_id: null as number | null, note: '' });
const fmt = (iso: string) => new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' });
</script>

<template>
    <Head title="Stocktakes" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <form v-if="canManage" class="flex max-w-3xl flex-wrap items-end gap-2 rounded-lg border p-4" @submit.prevent="form.post(route('stocktakes.store'))">
                <div class="grid gap-1">
                    <label class="text-sm font-medium">Count what</label>
                    <select v-model="form.scope_location_id" class="h-9 rounded-md border bg-background px-2 text-sm" required>
                        <option :value="null" disabled>Site, zone or bin…</option>
                        <option v-for="s in scopes" :key="s.id" :value="s.id">{{ s.label }}</option>
                    </select>
                </div>
                <input v-model="form.note" placeholder="Note (optional)" class="h-9 flex-1 rounded-md border bg-background px-2 text-sm" />
                <Button type="submit" :disabled="form.processing">Start stocktake</Button>
                <InputError :message="form.errors.scope_location_id" class="w-full" />
            </form>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">Number</th><th>Scope</th><th>Status</th><th class="text-right">Lines</th><th>Started</th><th>By</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="s in stocktakes.data" :key="s.id" class="border-b">
                        <td class="py-2 font-mono">{{ s.number }}</td>
                        <td>{{ s.scope }}</td>
                        <td class="capitalize">{{ s.status }}</td>
                        <td class="text-right">{{ s.lines }}</td>
                        <td>{{ fmt(s.snapshot_at) }}</td>
                        <td>{{ s.created_by }}</td>
                        <td class="text-right"><Link :href="route('stocktakes.show', s.id)" class="underline">Open</Link></td>
                    </tr>
                </tbody>
            </table>
            <Pagination :links="stocktakes.links" />
        </div>
    </AppLayout>
</template>
