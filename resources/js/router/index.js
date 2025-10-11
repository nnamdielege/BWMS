import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    // Auth Routes
    {
        path: '/login',
        name: 'login',
        component: () => import('../views/Auth/Login.vue'),
        meta: { guest: true },
    },
    {
        path: '/register',
        name: 'register',
        component: () => import('../views/Auth/Register.vue'),
        meta: { guest: true },
    },

    // Dashboard
    {
        path: '/',
        name: 'dashboard',
        component: () => import('../views/Dashboard.vue'),
        meta: { requiresAuth: true },
    },

    // Products
    {
        path: '/products',
        name: 'products.index',
        component: () => import('../views/Products/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/products/create',
        name: 'products.create',
        component: () => import('../views/Products/Create.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/products/:id',
        name: 'products.show',
        component: () => import('../views/Products/Show.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/products/:id/edit',
        name: 'products.edit',
        component: () => import('../views/Products/Edit.vue'),
        meta: { requiresAuth: true },
    },

    // Inventory
    {
        path: '/inventory',
        name: 'inventory.index',
        component: () => import('../views/Inventory/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/inventory/adjust',
        name: 'inventory.adjust',
        component: () => import('../views/Inventory/Adjust.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/inventory/transfer',
        name: 'inventory.transfer',
        component: () => import('../views/Inventory/Transfer.vue'),
        meta: { requiresAuth: true },
    },

    // Sales Orders
    {
        path: '/sales-orders',
        name: 'sales-orders.index',
        component: () => import('../views/Orders/Sales/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/sales-orders/create',
        name: 'sales-orders.create',
        component: () => import('../views/Orders/Sales/Create.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/sales-orders/:id',
        name: 'sales-orders.show',
        component: () => import('../views/Orders/Sales/Show.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/sales-orders/:id/edit',
        name: 'sales-orders.edit',
        component: () => import('../views/Orders/Sales/Edit.vue'),
        meta: { requiresAuth: true },
    },

    // Purchase Orders
    {
        path: '/purchase-orders',
        name: 'purchase-orders.index',
        component: () => import('../views/Orders/Purchase/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/purchase-orders/create',
        name: 'purchase-orders.create',
        component: () => import('../views/Orders/Purchase/Create.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/purchase-orders/:id',
        name: 'purchase-orders.show',
        component: () => import('../views/Orders/Purchase/Show.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/purchase-orders/:id/edit',
        name: 'purchase-orders.edit',
        component: () => import('../views/Orders/Purchase/Edit.vue'),
        meta: { requiresAuth: true },
    },

    // Customers
    {
        path: '/customers',
        name: 'customers.index',
        component: () => import('../views/Customers/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/customers/create',
        name: 'customers.create',
        component: () => import('../views/Customers/Create.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/customers/:id',
        name: 'customers.show',
        component: () => import('../views/Customers/Show.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/customers/:id/edit',
        name: 'customers.edit',
        component: () => import('../views/Customers/Edit.vue'),
        meta: { requiresAuth: true },
    },

    // Suppliers
    {
        path: '/suppliers',
        name: 'suppliers.index',
        component: () => import('../views/Suppliers/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/suppliers/create',
        name: 'suppliers.create',
        component: () => import('../views/Suppliers/Create.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/suppliers/:id',
        name: 'suppliers.show',
        component: () => import('../views/Suppliers/Show.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/suppliers/:id/edit',
        name: 'suppliers.edit',
        component: () => import('../views/Suppliers/Edit.vue'),
        meta: { requiresAuth: true },
    },

    // Warehouses
    {
        path: '/warehouses',
        name: 'warehouses.index',
        component: () => import('../views/Warehouses/Index.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/warehouses/create',
        name: 'warehouses.create',
        component: () => import('../views/Warehouses/Create.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/warehouses/:id',
        name: 'warehouses.show',
        component: () => import('../views/Warehouses/Show.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/warehouses/:id/edit',
        name: 'warehouses.edit',
        component: () => import('../views/Warehouses/Edit.vue'),
        meta: { requiresAuth: true },
    },

    // Reports
    {
        path: '/reports',
        name: 'reports',
        component: () => import('../views/Reports/Index.vue'),
        meta: { requiresAuth: true },
    },

    // Settings
    {
        path: '/settings',
        name: 'settings',
        component: () => import('../views/Settings/Index.vue'),
        meta: { requiresAuth: true },
    },

    // Profile
    {
        path: '/profile',
        name: 'profile',
        component: () => import('../views/Profile/Index.vue'),
        meta: { requiresAuth: true },
    },

    // Notifications
    {
        path: '/notifications',
        name: 'notifications',
        component: () => import('../views/Notifications/Index.vue'),
        meta: { requiresAuth: true },
    },

    // 404 Not Found
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('../views/Auth/Login.vue'),
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior(to, from, savedPosition) {
        if (savedPosition) {
            return savedPosition;
        } else {
            return { top: 0 };
        }
    },
});

// Navigation guards
router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();

    // Check if user is authenticated
    if (!authStore.isAuthenticated && authStore.token) {
        await authStore.checkAuth();
    }

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        next('/login');
    } else if (to.meta.guest && authStore.isAuthenticated) {
        next('/');
    } else {
        next();
    }
});

export default router;