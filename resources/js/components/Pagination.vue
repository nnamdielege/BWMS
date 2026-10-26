<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
}>();
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap gap-1 text-sm" aria-label="Pagination">
        <template v-for="(link, i) in links" :key="i">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="rounded border px-3 py-1"
                :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                ><span v-html="link.label"
            /></Link>
            <span v-else class="rounded border px-3 py-1 text-muted-foreground" v-html="link.label" />
        </template>
    </nav>
</template>
