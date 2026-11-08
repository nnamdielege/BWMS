<script setup lang="ts">
import { packHint, packLabel, toBase, unitLabel, type Pack, type PackEntry } from '@/lib/packs';
import { computed } from 'vue';

/**
 * A quantity typed as packs + loose base units, with the pack spelled out next
 * to the boxes ("1 Case = 12 each"). The server converts to base units.
 */
const props = withDefaults(defineProps<{ pack: Pack; size?: 'sm' | 'lg'; disabled?: boolean; showTotal?: boolean }>(), {
    size: 'sm',
    disabled: false,
    showTotal: true,
});
const model = defineModel<PackEntry>({ required: true });
const emit = defineEmits<{ commit: [] }>();

const box = computed(() =>
    props.size === 'lg'
        ? 'h-14 w-24 rounded-lg border bg-background px-3 text-2xl tabular-nums'
        : 'h-8 w-16 rounded-md border bg-background px-2 text-right text-sm tabular-nums',
);
const set = (field: keyof PackEntry, value: string) => {
    const n = value === '' ? null : Math.max(0, Math.floor(Number(value)));
    model.value = { ...model.value, [field]: Number.isNaN(n) ? null : n };
};
const blank = computed(() => model.value.packs === null && model.value.each === null);
// Commit once focus leaves both boxes, not when moving from the pack box to the loose box.
// Enter commits too, and isn't stopped, so a surrounding form still submits.
const leave = (e: FocusEvent) => {
    if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node | null)) emit('commit');
};
</script>

<template>
    <div class="inline-flex flex-col gap-0.5" :class="size === 'lg' ? 'items-start' : 'items-end'" @focusout="leave">
        <div class="flex flex-wrap items-center gap-1">
            <template v-if="pack.per > 1">
                <input
                    :value="model.packs ?? ''"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    :disabled="disabled"
                    :class="box"
                    :aria-label="pack.name"
                    @input="set('packs', ($event.target as HTMLInputElement).value)"
                    @keydown.enter="emit('commit')"
                />
                <span :class="size === 'lg' ? 'text-base font-medium' : 'text-xs'">{{ pack.name }}</span>
                <span class="text-xs text-muted-foreground">+</span>
            </template>
            <input
                :value="model.each ?? ''"
                type="number"
                inputmode="numeric"
                min="0"
                :disabled="disabled"
                :class="box"
                :aria-label="pack.base_unit"
                @input="set('each', ($event.target as HTMLInputElement).value)"
                @keydown.enter="emit('commit')"
            />
            <span :class="size === 'lg' ? 'text-base font-medium' : 'text-xs'">{{ unitLabel(pack.base_unit, 2) }}</span>
        </div>
        <span v-if="packHint(pack)" class="text-xs text-muted-foreground">
            {{ packHint(pack) }}<template v-if="showTotal && !blank && pack.per > 1">{{ ' · ' + packLabel(toBase(model, pack.per), pack) }}</template>
        </span>
    </div>
</template>
