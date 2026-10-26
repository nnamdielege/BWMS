<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useCan } from '@/composables/useCan';
import { type NavGroup, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    groups: NavGroup[];
}>();

const page = usePage<SharedData>();
const can = useCan();

const visibleGroups = computed(() =>
    props.groups
        .map((group) => ({ ...group, items: group.items.filter((item) => !item.permission || can(item.permission)) }))
        .filter((group) => group.items.length > 0),
);

const isActive = (href: string) => page.url === href || page.url.startsWith(href + '/') || page.url.startsWith(href + '?');
</script>

<template>
    <SidebarGroup v-for="group in visibleGroups" :key="group.label" class="px-2 py-0">
        <SidebarGroupLabel>{{ group.label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.title">
                <SidebarMenuButton as-child :is-active="isActive(item.href)" :tooltip="item.title">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
