<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Supplier {
    id: number;
    code: string;
    name: string;
    contact_name: string | null;
    email: string | null;
    cc_emails: string | null;
    phone: string | null;
    address: string | null;
    payment_terms: string | null;
    lead_time_days: number | null;
    notes: string | null;
    is_active: boolean;
}

const props = defineProps<{
    supplier: Supplier | null;
    recentOrders?: { id: number; number: string; status: string; order_date: string; total: string }[];
}>();

const can = useCan();
const readOnly = !can('suppliers.manage');
const s = props.supplier;
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Suppliers', href: '/suppliers' },
    { title: s ? s.name : 'New supplier', href: '#' },
];
const form = useForm({
    code: s?.code ?? '',
    name: s?.name ?? '',
    contact_name: s?.contact_name ?? '',
    email: s?.email ?? '',
    cc_emails: s?.cc_emails ?? '',
    phone: s?.phone ?? '',
    address: s?.address ?? '',
    payment_terms: s?.payment_terms ?? '',
    lead_time_days: s?.lead_time_days ?? null,
    notes: s?.notes ?? '',
    is_active: s?.is_active ?? true,
});
const submit = () => (s ? form.put(route('suppliers.update', s.id), { preserveScroll: true }) : form.post(route('suppliers.store')));
</script>

<template>
    <Head :title="supplier ? supplier.name : 'New supplier'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="max-w-3xl space-y-4 p-4" @submit.prevent="submit">
            <fieldset :disabled="readOnly" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1"><Label>Code</Label><Input v-model="form.code" class="font-mono uppercase" required /><InputError :message="form.errors.code" /></div>
                <div class="grid gap-1"><Label>Name</Label><Input v-model="form.name" required /><InputError :message="form.errors.name" /></div>
                <div class="grid gap-1"><Label>Contact name</Label><Input v-model="form.contact_name" /></div>
                <div class="grid gap-1"><Label>Phone</Label><Input v-model="form.phone" /></div>
                <div class="grid gap-1"><Label>Order email (POs are sent here)</Label><Input v-model="form.email" type="email" /><InputError :message="form.errors.email" /></div>
                <div class="grid gap-1"><Label>CC emails (comma-separated)</Label><Input v-model="form.cc_emails" /><InputError :message="form.errors.cc_emails" /></div>
                <div class="grid gap-1"><Label>Payment terms</Label><Input v-model="form.payment_terms" placeholder="e.g. 30 days EOM" /></div>
                <div class="grid gap-1"><Label>Lead time (days)</Label><Input v-model.number="form.lead_time_days" type="number" min="0" /></div>
                <div class="grid gap-1 sm:col-span-2"><Label>Address</Label><textarea v-model="form.address" rows="3" class="rounded-md border bg-background p-2 text-sm" /></div>
                <div class="grid gap-1 sm:col-span-2"><Label>Notes</Label><textarea v-model="form.notes" rows="2" class="rounded-md border bg-background p-2 text-sm" /></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" /> Active</label>
            </fieldset>
            <div class="flex gap-2">
                <Button v-if="!readOnly" type="submit" :disabled="form.processing">{{ supplier ? 'Save' : 'Create supplier' }}</Button>
                <Button variant="ghost" as-child><Link :href="route('suppliers.index')">Back</Link></Button>
            </div>

            <section v-if="recentOrders?.length" class="pt-4">
                <h2 class="mb-2 font-semibold">Recent purchase orders</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="o in recentOrders" :key="o.id">
                        <Link :href="route('purchase-orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                        · {{ o.order_date.slice(0, 10) }} · {{ o.status.replace('_', ' ') }} · ${{ Number(o.total).toFixed(2) }}
                    </li>
                </ul>
            </section>
        </form>
    </AppLayout>
</template>
