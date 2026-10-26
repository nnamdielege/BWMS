<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Boxes, ClipboardList, LayoutGrid, Package, ScrollText, Users, Warehouse } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

// Later steps add Stock, Purchasing, Orders and Sync groups here.
const navGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [{ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid }],
    },
    {
        label: 'Stock',
        items: [
            { title: 'Stock on hand', href: '/stock', icon: Boxes, permission: 'stock.view' },
            { title: 'Stocktakes', href: '/stocktakes', icon: ClipboardList, permission: 'stocktakes.count' },
        ],
    },
    {
        label: 'Catalogue',
        items: [
            { title: 'Products', href: '/products', icon: Package, permission: 'products.view' },
            { title: 'Locations', href: '/locations', icon: Warehouse, permission: 'locations.view' },
        ],
    },
    {
        label: 'Admin',
        items: [
            { title: 'Users', href: '/admin/users', icon: Users, permission: 'users.view' },
            { title: 'Audit log', href: '/admin/audit-log', icon: ScrollText, permission: 'audit.view' },
        ],
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :groups="navGroups" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
