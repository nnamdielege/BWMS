<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    settings: Record<string, string>;
    requireBinScan: boolean;
    reorderEmailTo: string;
    reorderEmailOn: boolean;
    logo: { url: string; uploaded: boolean };
    mail: { mailer: string; problem: string | null; from: string | null };
    testEmail: { ok: boolean; to: string; message: string } | null;
}>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Company settings', href: '/admin/settings' }];

const form = useForm({
    company_name: props.settings['company.name'] ?? '',
    company_abn: props.settings['company.abn'] ?? '',
    company_address: props.settings['company.address'] ?? '',
    company_phone: props.settings['company.phone'] ?? '',
    company_email: props.settings['company.email'] ?? '',
    company_delivery_address: props.settings['company.delivery_address'] ?? '',
    po_terms: props.settings['po.terms'] ?? '',
    require_bin_scan: props.requireBinScan,
    reorder_email_to: props.reorderEmailTo,
});

const logoForm = useForm<{ logo: File | null }>({ logo: null });
const uploadLogo = () => logoForm.post(route('admin.settings.logo.store'), { preserveScroll: true, onSuccess: () => logoForm.reset() });
const removeLogo = () => router.delete(route('admin.settings.logo.destroy'), { preserveScroll: true });

const testing = ref(false);
const sendTest = () =>
    router.post(route('admin.settings.test-email'), {}, { preserveScroll: true, onStart: () => (testing.value = true), onFinish: () => (testing.value = false) });
</script>

<template>
    <Head title="Company settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="max-w-2xl space-y-4 p-4" @submit.prevent="form.put(route('admin.settings.update'), { preserveScroll: true })">
            <p class="text-sm text-muted-foreground">Shown on purchase orders and supplier emails.</p>
            <div class="grid gap-1">
                <Label>Company name</Label><Input v-model="form.company_name" required /><InputError :message="form.errors.company_name" />
            </div>
            <div class="grid gap-1"><Label>ABN</Label><Input v-model="form.company_abn" /></div>
            <div class="grid gap-1">
                <Label>Address</Label><textarea v-model="form.company_address" rows="3" class="rounded-md border bg-background p-2 text-sm" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1"><Label>Phone</Label><Input v-model="form.company_phone" /></div>
                <div class="grid gap-1">
                    <Label>Email (reply-to for PO emails)</Label><Input v-model="form.company_email" type="email" /><InputError
                        :message="form.errors.company_email"
                    />
                </div>
            </div>
            <div class="grid gap-1">
                <Label>Default delivery address</Label
                ><textarea v-model="form.company_delivery_address" rows="3" class="rounded-md border bg-background p-2 text-sm" />
            </div>
            <div class="grid gap-1">
                <Label>PO terms (footer)</Label><textarea v-model="form.po_terms" rows="3" class="rounded-md border bg-background p-2 text-sm" />
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.require_bin_scan" type="checkbox" class="mt-0.5" aria-label="Require bin scan when picking" />
                <span>
                    <span class="font-medium">Require bin scan when picking</span>
                    <span class="block text-muted-foreground">When off, the product scan alone confirms a line. The bin still shows in large letters.</span>
                </span>
            </label>
            <div class="grid gap-1">
                <Label for="reorder_email_to">Reorder email recipients</Label>
                <Input id="reorder_email_to" v-model="form.reorder_email_to" placeholder="name@example.com, other@example.com" />
                <span class="text-xs text-muted-foreground">
                    Who gets the 7am reorder summary, comma-separated. Leave empty to send it to no one.
                    {{ reorderEmailOn ? '' : 'The email is switched off: turn it on in Reorder suggestions → Settings.' }}
                </span>
                <InputError :message="form.errors.reorder_email_to" />
            </div>
            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>

        <form class="max-w-2xl space-y-3 border-t p-4" @submit.prevent="uploadLogo">
            <h2 class="font-semibold">Logo</h2>
            <p class="text-sm text-muted-foreground">
                Used on purchase orders, packing slips, bin labels and emails. PNG or JPEG, up to 1 MB; a square image of at least 300 px prints best.
                {{ logo.uploaded ? '' : 'Currently the bundled Local Selection logo.' }}
            </p>
            <img :src="logo.url" alt="Current logo" class="size-24 rounded border bg-white object-contain p-1" />
            <input
                type="file"
                accept="image/png,image/jpeg"
                class="block text-sm"
                @input="logoForm.logo = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
            <InputError :message="logoForm.errors.logo" />
            <div class="flex gap-2">
                <Button type="submit" :disabled="logoForm.processing || !logoForm.logo">Upload logo</Button>
                <Button v-if="logo.uploaded" type="button" variant="outline" @click="removeLogo">Use the bundled logo</Button>
            </div>
        </form>

        <section class="max-w-2xl space-y-3 border-t p-4">
            <h2 class="font-semibold">Email</h2>
            <p
                v-if="mail.problem"
                class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-800 dark:bg-red-950 dark:text-red-200"
                role="alert"
            >
                {{ mail.problem }}
            </p>
            <p class="text-sm text-muted-foreground">
                Mailer "{{ mail.mailer }}"{{ mail.from ? `, from ${mail.from}` : '' }}. Send a test to yourself to check that supplier emails will be delivered.
            </p>
            <Button type="button" variant="outline" :disabled="testing" @click="sendTest">{{ testing ? 'Sending…' : 'Send test email' }}</Button>
            <p
                v-if="testEmail"
                class="rounded-lg border p-3 text-sm break-words"
                :class="testEmail.ok ? 'border-green-300 bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200' : 'border-red-300 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200'"
                role="status"
            >
                <span class="font-semibold">{{ testEmail.ok ? 'Sent.' : `Failed to send to ${testEmail.to}:` }}</span> {{ testEmail.message }}
            </p>
        </section>
    </AppLayout>
</template>
