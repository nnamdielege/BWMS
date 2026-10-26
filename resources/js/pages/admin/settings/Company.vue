<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ settings: Record<string, string> }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Company settings', href: '/admin/settings' }];

const form = useForm({
    company_name: props.settings['company.name'] ?? '',
    company_abn: props.settings['company.abn'] ?? '',
    company_address: props.settings['company.address'] ?? '',
    company_phone: props.settings['company.phone'] ?? '',
    company_email: props.settings['company.email'] ?? '',
    company_delivery_address: props.settings['company.delivery_address'] ?? '',
    po_terms: props.settings['po.terms'] ?? '',
});
</script>

<template>
    <Head title="Company settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="max-w-2xl space-y-4 p-4" @submit.prevent="form.put(route('admin.settings.update'), { preserveScroll: true })">
            <p class="text-sm text-muted-foreground">Shown on purchase orders and supplier emails.</p>
            <div class="grid gap-1"><Label>Company name</Label><Input v-model="form.company_name" required /><InputError :message="form.errors.company_name" /></div>
            <div class="grid gap-1"><Label>ABN</Label><Input v-model="form.company_abn" /></div>
            <div class="grid gap-1"><Label>Address</Label><textarea v-model="form.company_address" rows="3" class="rounded-md border bg-background p-2 text-sm" /></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1"><Label>Phone</Label><Input v-model="form.company_phone" /></div>
                <div class="grid gap-1"><Label>Email (reply-to for PO emails)</Label><Input v-model="form.company_email" type="email" /><InputError :message="form.errors.company_email" /></div>
            </div>
            <div class="grid gap-1"><Label>Default delivery address</Label><textarea v-model="form.company_delivery_address" rows="3" class="rounded-md border bg-background p-2 text-sm" /></div>
            <div class="grid gap-1"><Label>PO terms (footer)</Label><textarea v-model="form.po_terms" rows="3" class="rounded-md border bg-background p-2 text-sm" /></div>
            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </AppLayout>
</template>
