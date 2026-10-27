<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Customer {
    id: number;
    code: string;
    name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    delivery_street: string | null;
    delivery_suburb: string | null;
    delivery_postcode: string | null;
    delivery_area_id: number | null;
    ordermentum_retailer_id: string | null;
    portal_user_id: number | null;
    notes: string | null;
    is_active: boolean;
}

const props = defineProps<{ customer: Customer | null; deliveryAreas: { id: number; name: string }[] }>();
const readOnly = !useCan()('orders.manage');
const c = props.customer;
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: c ? c.name : 'New customer', href: '#' },
];
const form = useForm({
    code: c?.code ?? '',
    name: c?.name ?? '',
    contact_name: c?.contact_name ?? '',
    email: c?.email ?? '',
    phone: c?.phone ?? '',
    address: c?.address ?? '',
    delivery_street: c?.delivery_street ?? '',
    delivery_suburb: c?.delivery_suburb ?? '',
    delivery_postcode: c?.delivery_postcode ?? '',
    delivery_area_id: c?.delivery_area_id ?? null,
    ordermentum_retailer_id: c?.ordermentum_retailer_id ?? '',
    portal_user_id: c?.portal_user_id ?? null,
    notes: c?.notes ?? '',
    is_active: c?.is_active ?? true,
});
const submit = () =>
    form
        .transform((d) => ({ ...d, ordermentum_retailer_id: d.ordermentum_retailer_id || null, delivery_postcode: d.delivery_postcode || null }))
        [c ? 'put' : 'post'](c ? route('customers.update', c.id) : route('customers.store'), { preserveScroll: true });
</script>

<template>
    <Head :title="customer ? customer.name : 'New customer'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="max-w-3xl space-y-4 p-4" @submit.prevent="submit">
            <fieldset :disabled="readOnly" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1"><Label>Code</Label><Input v-model="form.code" class="font-mono uppercase" required /><InputError :message="form.errors.code" /></div>
                <div class="grid gap-1"><Label>Business name</Label><Input v-model="form.name" required /><InputError :message="form.errors.name" /></div>
                <div class="grid gap-1"><Label>Contact</Label><Input v-model="form.contact_name" /></div>
                <div class="grid gap-1"><Label>Phone</Label><Input v-model="form.phone" /></div>
                <div class="grid gap-1"><Label>Email</Label><Input v-model="form.email" type="email" /><InputError :message="form.errors.email" /></div>
                <div class="grid gap-1"><Label>Ordermentum retailer ID</Label><Input v-model="form.ordermentum_retailer_id" class="font-mono" /><InputError :message="form.errors.ordermentum_retailer_id" /></div>
                <div class="grid gap-1"><Label>Portal user ID</Label><Input v-model.number="form.portal_user_id" type="number" /><InputError :message="form.errors.portal_user_id" /></div>
                <div class="grid gap-1 sm:col-span-2"><Label>Address (as imported)</Label><textarea v-model="form.address" rows="2" class="rounded-md border bg-background p-2 text-sm" /></div>
                <div class="grid gap-1 sm:col-span-2"><Label>Delivery street</Label><Input v-model="form.delivery_street" /></div>
                <div class="grid gap-1"><Label>Suburb</Label><Input v-model="form.delivery_suburb" /></div>
                <div class="grid gap-1"><Label>Postcode</Label><Input v-model="form.delivery_postcode" inputmode="numeric" maxlength="4" class="font-mono" /><InputError :message="form.errors.delivery_postcode" /></div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label>Delivery area</Label>
                    <select v-model="form.delivery_area_id" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option :value="null">Automatic (from postcode / suburb)</option>
                        <option v-for="a in deliveryAreas" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1 sm:col-span-2"><Label>Notes</Label><textarea v-model="form.notes" rows="2" class="rounded-md border bg-background p-2 text-sm" /></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" /> Active</label>
            </fieldset>
            <div class="flex gap-2">
                <Button v-if="!readOnly" type="submit" :disabled="form.processing">{{ customer ? 'Save' : 'Create customer' }}</Button>
                <Button variant="ghost" as-child><Link :href="route('customers.index')">Back</Link></Button>
            </div>
        </form>
    </AppLayout>
</template>
