<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps<{
    enabled: boolean;
    confirmed: boolean;
    required: boolean;
    status?: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Two-factor authentication', href: '/settings/two-factor' }];

const qrSvg = ref<string | null>(null);
const secret = ref<string | null>(null);
const recoveryCodes = ref<string[]>([]);
const loadError = ref<string | null>(null);

// Fortify's QR / secret / recovery-code endpoints return JSON.
const getJson = async <T,>(url: string): Promise<T | null> => {
    const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (res.status === 423) {
        // Password confirmation expired: confirm, then come back here.
        router.visit(route('password.confirm'));
        return null;
    }
    if (!res.ok) {
        loadError.value = 'Could not load two-factor details. Refresh and try again.';
        return null;
    }
    return (await res.json()) as T;
};

const loadSetup = async () => {
    const qr = await getJson<{ svg: string }>(route('two-factor.qr-code'));
    const key = await getJson<{ secretKey: string }>(route('two-factor.secret-key'));
    qrSvg.value = qr?.svg ?? null;
    secret.value = key?.secretKey ?? null;
};
const loadRecoveryCodes = async () => {
    recoveryCodes.value = (await getJson<string[]>(route('two-factor.recovery-codes'))) ?? [];
};

// Watch, not onMounted: enabling and confirming post back to this same page, which
// Inertia re-renders with new props without remounting it.
watch(
    () => [props.enabled, props.confirmed, props.status] as const,
    async ([enabled, confirmed, status]) => {
        if (enabled && !confirmed && !qrSvg.value) await loadSetup();
        if (confirmed && status === 'two-factor-authentication-confirmed' && !recoveryCodes.value.length) await loadRecoveryCodes();
    },
    { immediate: true },
);

const enable = () => router.post(route('two-factor.enable'), {}, { preserveScroll: true });
const confirmForm = useForm({ code: '' });
const confirm = () => confirmForm.post(route('two-factor.confirm'), { preserveScroll: true, errorBag: 'confirmTwoFactorAuthentication' });
const regenerate = () =>
    router.post(route('two-factor.regenerate-recovery-codes'), {}, { preserveScroll: true, onSuccess: () => loadRecoveryCodes() });
const disable = () => confirm_('Turn off two-factor authentication?') && router.delete(route('two-factor.disable'), { preserveScroll: true });
const confirm_ = (msg: string) => window.confirm(msg);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Two-factor authentication" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Two-factor authentication"
                    :description="required ? 'Required for administrators.' : 'Optional. Adds a code from your authenticator app at sign-in.'"
                />
                <p v-if="loadError" class="text-sm text-red-600">{{ loadError }}</p>

                <!-- Not enabled -->
                <div v-if="!enabled" class="space-y-3">
                    <p class="text-sm">
                        Use an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…). You'll be asked for your password first.
                    </p>
                    <Button @click="enable">Set up two-factor</Button>
                </div>

                <!-- Enabled, awaiting confirmation -->
                <div v-else-if="!confirmed" class="space-y-4">
                    <p class="text-sm">Scan this QR code with your authenticator app, then enter the 6-digit code it shows.</p>
                    <div v-if="qrSvg" class="inline-block rounded bg-white p-3" v-html="qrSvg" />
                    <p v-if="secret" class="text-sm">
                        Or enter this key manually: <code class="font-mono">{{ secret }}</code>
                    </p>
                    <form class="flex items-start gap-2" @submit.prevent="confirm">
                        <div>
                            <Input
                                v-model="confirmForm.code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                placeholder="123456"
                                class="w-36"
                                autofocus
                            />
                            <InputError :message="confirmForm.errors.code" />
                        </div>
                        <Button type="submit" :disabled="confirmForm.processing">Confirm</Button>
                    </form>
                </div>

                <!-- Confirmed -->
                <div v-else class="space-y-4">
                    <p class="text-sm font-medium text-green-700">Two-factor authentication is on.</p>
                    <div v-if="recoveryCodes.length" class="space-y-2 rounded-md border p-4">
                        <p class="text-sm font-medium">Recovery codes: store these somewhere safe. Each works once if you lose your phone.</p>
                        <ul class="grid grid-cols-2 gap-1 font-mono text-sm">
                            <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
                        </ul>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" @click="loadRecoveryCodes">Show recovery codes</Button>
                        <Button variant="outline" @click="regenerate">Regenerate recovery codes</Button>
                        <Button v-if="!required" variant="ghost" @click="disable">Turn off</Button>
                    </div>
                    <p v-if="required" class="text-xs text-muted-foreground">
                        Administrators can't turn this off. If you lose your device, use a recovery code or ask another admin to reset it.
                    </p>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
