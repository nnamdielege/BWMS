<script setup lang="ts">
import { Button } from '@/components/ui/button';

/** Choose a range, then act on all of it. Extra controls go in the default slot, before the button. */
defineProps<{ modelValue: string; options: [string, number][]; label: string }>();
defineEmits<{ 'update:modelValue': [value: string]; apply: [] }>();
</script>

<template>
    <label class="flex flex-col gap-1">
        <span class="font-medium">{{ label }}</span>
        <select
            :value="modelValue"
            class="h-9 rounded-md border border-input bg-background px-2"
            @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        >
            <option value="">Choose a range</option>
            <option v-for="[name, n] in options" :key="name" :value="name">{{ name }} ({{ n }})</option>
        </select>
    </label>
    <slot />
    <Button size="sm" variant="outline" :disabled="!modelValue" @click="$emit('apply')">Apply to whole range</Button>
</template>
