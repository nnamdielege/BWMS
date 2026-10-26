<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

const submit = () =>
    form
        .transform((d) => (useRecovery.value ? { recovery_code: d.recovery_code } : { code: d.code }))
        .post(route('two-factor.login.store'), { onFinish: () => form.reset() });
</script>

<template>
    <AuthBase
        title="Two-factor authentication"
        :description="useRecovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.'"
    >
        <Head title="Two-factor challenge" />

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <template v-if="!useRecovery">
                <Input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="123456" />
                <InputError :message="form.errors.code" />
            </template>
            <template v-else>
                <Input v-model="form.recovery_code" autocomplete="off" autofocus placeholder="xxxxxxxxxx-xxxxxxxxxx" />
                <InputError :message="form.errors.recovery_code" />
            </template>

            <Button type="submit" class="w-full" :disabled="form.processing">Continue</Button>
            <button type="button" class="text-sm underline" @click="useRecovery = !useRecovery">
                {{ useRecovery ? 'Use an authenticator code instead' : 'Use a recovery code' }}
            </button>
        </form>
    </AuthBase>
</template>
