import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User | null;
    permissions: string[];
    roles: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Hide the item unless the user has this permission. */
    permission?: string;
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: { success: string | null; error: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

export type BreadcrumbItemType = BreadcrumbItem;
