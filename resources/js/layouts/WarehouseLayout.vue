<script setup lang="ts">
/** Lean, touch-first layout for handhelds: title bar, back link, flash messages, big content. */
import FlashMessages from '@/components/FlashMessages.vue';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{ title: string; back?: string }>();
const page = usePage<SharedData>();
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="sticky top-0 z-10 flex h-14 items-center gap-3 border-b bg-background px-3">
            <Link v-if="back" :href="back" class="flex h-10 min-w-10 items-center justify-center rounded-md border text-lg" aria-label="Back">←</Link>
            <h1 class="truncate text-lg font-semibold">{{ title }}</h1>
            <span class="ml-auto truncate text-xs text-muted-foreground">{{ page.props.auth.user?.name }}</span>
        </header>
        <FlashMessages />
        <main class="mx-auto max-w-2xl p-3">
            <slot />
        </main>
    </div>
</template>
