<script setup lang="ts">
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<SharedData>();
const flash = computed(() => page.props.flash);
</script>

<template>
    <div v-if="flash.success || flash.error" class="px-4 pt-4">
        <div v-if="flash.success" role="status" class="rounded-md border border-green-300 bg-green-50 px-4 py-2 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            {{ flash.success }}
        </div>
        <div v-if="flash.error" role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            {{ flash.error }}
        </div>
    </div>
</template>
