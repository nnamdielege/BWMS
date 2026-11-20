<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Barcode,
    Boxes,
    CalendarClock,
    CalendarRange,
    ChartColumn,
    ClipboardList,
    Combine,
    DollarSign,
    FileText,
    Import,
    Layers,
    LayoutGrid,
    ListChecks,
    ListRestart,
    MapPin,
    Package,
    PackageCheck,
    ReceiptText,
    RefreshCw,
    ScanLine,
    ScrollText,
    Settings,
    ShieldAlert,
    ShoppingCart,
    Snail,
    Store,
    Tags,
    Trash2,
    Truck,
    Users,
    Wallet,
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
            { title: 'Consolidate', href: '/orders/consolidate', icon: Combine, permission: 'orders.manage' },
            { title: 'Pick & pack (phone/handheld)', href: '/pick', icon: ScanLine, permission: 'picking.perform' },
            { title: 'Pick lists (desktop)', href: '/pick-lists', icon: ListChecks, permission: 'picking.perform' },
            { title: 'Customers', href: '/customers', icon: Store, permission: 'orders.view' },
        ],
    },
    {
        label: 'Stock',
        items: [
            { title: 'Stock on hand', href: '/stock', icon: Boxes, permission: 'stock.view' },
            { title: 'Stocktakes', href: '/stocktakes', icon: ClipboardList, permission: 'stocktakes.count' },
            { title: 'Expiring soon', href: '/stock/expiring', icon: CalendarClock, permission: 'stock.view' },
            { title: 'Quarantine', href: '/stock/quarantine', icon: ShieldAlert, permission: 'stock.adjust' },
        ],
    },
    {
        label: 'Purchasing',
        items: [
            { title: 'Purchase orders', href: '/purchase-orders', icon: FileText, permission: 'purchase_orders.view' },
            { title: 'Reorder suggestions', href: '/purchasing/reorder', icon: ListRestart, permission: 'purchase_orders.view' },
            { title: 'Budgets', href: '/purchasing/budgets', icon: Wallet, permission: 'purchase_orders.view' },
            { title: 'Cash out', href: '/purchasing/cash-out', icon: CalendarRange, permission: 'purchase_orders.view' },
            { title: 'Slow movers', href: '/purchasing/slow-movers', icon: Snail, permission: 'purchase_orders.view' },
            { title: 'Supplier credits', href: '/purchasing/supplier-credits', icon: ReceiptText, permission: 'purchase_orders.view' },
            { title: 'Suppliers', href: '/suppliers', icon: Truck, permission: 'suppliers.view' },
        ],
    },
    {
        label: 'Catalogue',
        items: [
            { title: 'Products', href: '/products', icon: Package, permission: 'products.view' },
            { title: 'Product costs', href: '/product-costs', icon: DollarSign, permission: 'reports.view' },
            { title: 'Pack setup', href: '/product-packs', icon: Layers, permission: 'products.view' },
            { title: 'Import products', href: '/product-import', icon: Import, permission: 'products.manage' },
            { title: 'Batch tracking', href: '/batch-tracking', icon: Tags, permission: 'products.manage' },
            { title: 'Capture barcodes', href: '/capture-barcodes', icon: Barcode, permission: 'products.manage' },
            { title: 'Locations', href: '/locations', icon: Warehouse, permission: 'locations.view' },
        ],
    },
    {
        label: 'Reports',
        items: [
            { title: 'Stock value & margin', href: '/reports/margin', icon: ChartColumn, permission: 'reports.margin' },
            { title: 'Substitutions', href: '/reports/substitutions', icon: ArrowLeftRight, permission: 'reports.margin' },
            { title: 'Spoilage', href: '/reports/spoilage', icon: Trash2, permission: 'reports.margin' },
            { title: 'Stock received', href: '/reports/received', icon: PackageCheck, permission: 'reports.margin' },
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
            { title: 'Delivery areas', href: '/admin/delivery-areas', icon: MapPin, permission: 'settings.manage' },
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
