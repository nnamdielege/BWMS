<script setup lang="ts">
defineProps<{ label: string; value: string; sub?: string | null; tone?: 'warn' | null }>();
</script>

<template>
    <div class="rounded-lg border p-4" :class="tone === 'warn' ? 'border-amber-400 dark:border-amber-600' : ''">
        <div class="text-sm text-muted-foreground">{{ label }}</div>
        <div class="text-2xl font-semibold tabular-nums sm:text-3xl">{{ value }}</div>
        <div v-if="sub" class="text-xs text-muted-foreground">{{ sub }}</div>
        <slot />
    </div>
</template>
