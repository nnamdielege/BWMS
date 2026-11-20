<script setup lang="ts">
import { computed, ref } from 'vue';

/** One series of bars from a zero baseline, with a hover readout. Negative values sit at zero height. */
const props = defineProps<{
    points: { label: string; value: number; detail?: string }[];
    title: string;
    format: (n: number) => string;
}>();

const W = 640;
const H = 180;
const PAD = { l: 52, r: 8, t: 8, b: 24 };
const niceMax = computed(() => {
    const m = Math.max(1, ...props.points.map((p) => p.value));
    const step = Math.pow(10, Math.floor(Math.log10(m)));
    return Math.ceil(m / step) * step;
});
const ticks = computed(() => [0, 0.5, 1].map((f) => f * niceMax.value));
const slot = computed(() => (W - PAD.l - PAD.r) / Math.max(1, props.points.length));
const barW = computed(() => Math.max(2, Math.min(28, slot.value - 4)));
const y = (v: number) => PAD.t + (H - PAD.t - PAD.b) * (1 - Math.max(0, v) / niceMax.value);
const barPath = (i: number, v: number) => {
    const x = PAD.l + i * slot.value + (slot.value - barW.value) / 2;
    const top = y(v);
    const base = y(0);
    if (base - top < 0.5) return '';
    const r = Math.min(4, barW.value / 2, base - top);
    return `M${x},${base} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW.value - r} Q${x + barW.value},${top} ${x + barW.value},${top + r} V${base} Z`;
};
const labelEvery = computed(() => Math.ceil(props.points.length / 10));
const short = (t: number) => (t >= 1000 ? `${t / 1000}k` : String(t));
const hover = ref<number | null>(null);
</script>

<template>
    <div>
        <svg :viewBox="`0 0 ${W} ${H}`" class="w-full max-w-3xl text-muted-foreground" role="img" :aria-label="title">
            <g v-for="t in ticks" :key="t">
                <line :x1="PAD.l" :x2="W - PAD.r" :y1="y(t)" :y2="y(t)" stroke="currentColor" stroke-opacity="0.2" />
                <text :x="PAD.l - 6" :y="y(t) + 4" text-anchor="end" class="fill-current text-[10px] tabular-nums">{{ short(t) }}</text>
            </g>
            <g v-for="(p, i) in points" :key="i" @mouseenter="hover = i" @mouseleave="hover = null">
                <rect :x="PAD.l + i * slot" :y="PAD.t" :width="slot" :height="H - PAD.t - PAD.b" fill="transparent" />
                <path :d="barPath(i, p.value)" class="fill-sky-600 dark:fill-sky-400" :fill-opacity="hover === null || hover === i ? 1 : 0.5" />
                <text v-if="i % labelEvery === 0" :x="PAD.l + i * slot + slot / 2" :y="H - 8" text-anchor="middle" class="fill-current text-[10px]">
                    {{ p.label }}
                </text>
            </g>
        </svg>
        <div class="h-5 text-sm tabular-nums">
            <template v-if="hover !== null">
                {{ points[hover].label }}: <strong>{{ format(points[hover].value) }}</strong>
                <template v-if="points[hover].detail">, {{ points[hover].detail }}</template>
            </template>
        </div>
    </div>
</template>
