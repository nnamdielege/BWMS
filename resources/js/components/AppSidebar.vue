<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/vue3';
import {
    Boxes,
    ClipboardList,
    DollarSign,
    FileText,
    Layers,
    LayoutGrid,
    ListChecks,
    Package,
    RefreshCw,
    ScrollText,
    Settings,
    ShoppingCart,
    Store,
    Truck,
    Users,
    Warehouse,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const navGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [{ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid }],
    },
    {
        label: 'Orders',
        items: [
            { title: 'Orders', href: '/orders', icon: ShoppingCart, permission: 'orders.view' },
            { title: 'Pick lists', href: '/pick-lists', icon: ListChecks, permission: 'picking.perform' },
            { title: 'Customers', href: '/customers', icon: Store, permission: 'orders.view' },
        ],
    },
    {
        label: 'Stock',
        items: [
            { title: 'Stock on hand', href: '/stock', icon: Boxes, permission: 'stock.view' },
            { title: 'Stocktakes', href: '/stocktakes', icon: ClipboardList, permission: 'stocktakes.count' },
        ],
    },
    {
        label: 'Purchasing',
        items: [
            { title: 'Purchase orders', href: '/purchase-orders', icon: FileText, permission: 'purchase_orders.view' },
            { title: 'Suppliers', href: '/suppliers', icon: Truck, permission: 'suppliers.view' },
        ],
    },
    {
        label: 'Catalogue',
        items: [
            { title: 'Products', href: '/products', icon: Package, permission: 'products.view' },
            { title: 'Product costs', href: '/product-costs', icon: DollarSign, permission: 'reports.view' },
            { title: 'Pack setup', href: '/product-packs', icon: Layers, permission: 'products.view' },
            { title: 'Locations', href: '/locations', icon: Warehouse, permission: 'locations.view' },
        ],
    },
    {
        label: 'Integrations',
        items: [{ title: 'Sync', href: '/sync', icon: RefreshCw, permission: 'sync.view' }],
    },
    {
        label: 'Admin',
        items: [
            { title: 'Users', href: '/admin/users', icon: Users, permission: 'users.view' },
            { title: 'Audit log', href: '/admin/audit-log', icon: ScrollText, permission: 'audit.view' },
            { title: 'Company settings', href: '/admin/settings', icon: Settings, permission: 'settings.manage' },
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
