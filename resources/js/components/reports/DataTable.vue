<script setup lang="ts">
import { money, num, pct } from '@/lib/money';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Column, Row } from './types';

const props = withDefaults(defineProps<{ columns: Column[]; rows: Row[]; empty?: string; limit?: number }>(), {
    empty: 'Nothing in these dates.',
    limit: 50,
});

const all = ref(false);
const shown = computed(() => (all.value ? props.rows : props.rows.slice(0, props.limit)));
const numeric = (c: Column) => ['money', 'int', 'pct', 'cost'].includes(c.kind ?? 'text');
const show = (c: Column, row: Row) => {
    const v = row[c.key];
    if (v === null || v === undefined || v === '') return '—';
    switch (c.kind) {
        case 'money':
            return money(v);
        case 'cost':
            return `$${Number(v).toFixed(4)}`;
        case 'int':
            return num(v);
        case 'pct':
            return pct(v);
        default:
            return String(v);
    }
};
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b text-muted-foreground">
                <tr>
                    <th v-for="c in columns" :key="c.key" class="whitespace-nowrap py-1.5 pr-3 font-medium" :class="numeric(c) ? 'text-right' : ''">
                        {{ c.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(r, i) in shown" :key="i" class="border-b align-top">
                    <td
                        v-for="c in columns"
                        :key="c.key"
                        class="py-1 pr-3"
                        :class="[numeric(c) ? 'whitespace-nowrap text-right tabular-nums' : '', c.cellClass?.(r) ?? '']"
                    >
                        <Link v-if="c.href?.(r)" :href="c.href(r) ?? ''" class="underline">{{ show(c, r) }}</Link>
                        <template v-else>{{ show(c, r) }}</template>
                        <div v-if="c.sub?.(r)" class="text-xs text-muted-foreground">{{ c.sub(r) }}</div>
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td :colspan="columns.length" class="py-4 text-center text-muted-foreground">{{ empty }}</td>
                </tr>
            </tbody>
        </table>
        <button v-if="rows.length > limit" type="button" class="mt-2 text-sm underline" @click="all = !all">
            {{ all ? `Show the first ${limit}` : `Show all ${rows.length}` }}
        </button>
    </div>
</template>
