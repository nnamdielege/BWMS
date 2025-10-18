import { ref, computed } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const user = ref(null);
const roles = ref([]);
const permissions = ref([]);
const loading = ref(false);
const loaded = ref(false);

export function usePermissions() {
    const authStore = useAuthStore();

    /**
     * Fetch current user's permissions
     */
    const fetchPermissions = async () => {
        if (loaded.value) return;

        loading.value = true;
        try {
            const response = await axios.get('auth/me');
            
            console.log('Auth/me response:', response.data); // Debug log
            
            user.value = response.data.data.user;
            roles.value = response.data.data.roles || [];
            permissions.value = response.data.data.permissions || [];
            
            console.log('Loaded roles:', roles.value); // Debug log
            console.log('Loaded permissions:', permissions.value); // Debug log
            console.log('Is admin?', roles.value.includes('admin')); // Debug log
            
            loaded.value = true;
        } catch (error) {
            console.error('Error fetching permissions:', error);
            console.error('Error response:', error.response); // Debug log
        } finally {
            loading.value = false;
        }
    };

    /**
     * Check if user has a specific permission
     */
    const hasPermission = (permission) => {
        const result = permissions.value.includes(permission);
        console.log(`hasPermission(${permission}):`, result); // Debug log
        return result;
    };

    /**
     * Check if user has any of the specified permissions
     */
    const hasAnyPermission = (permissionList) => {
        const result = permissionList.some(permission => permissions.value.includes(permission));
        console.log(`hasAnyPermission([${permissionList}]):`, result); // Debug log
        return result;
    };

    /**
     * Check if user has all of the specified permissions
     */
    const hasAllPermissions = (permissionList) => {
        return permissionList.every(permission => permissions.value.includes(permission));
    };

    /**
     * Check if user has a specific role
     */
    const hasRole = (role) => {
        const result = roles.value.includes(role);
        console.log(`hasRole(${role}):`, result); // Debug log
        return result;
    };

    /**
     * Check if user has any of the specified roles
     */
    const hasAnyRole = (roleList) => {
        return roleList.some(role => roles.value.includes(role));
    };

    /**
     * Check if user has all of the specified roles
     */
    const hasAllRoles = (roleList) => {
        return roleList.every(role => roles.value.includes(role));
    };

    /**
     * Check if user is admin
     */
    const isAdmin = computed(() => {
        const result = hasRole('admin');
        console.log('isAdmin computed:', result, 'roles:', roles.value); // Debug log
        return result;
    });

    /**
     * Check if user has access to usage stats
     * Can view if: admin OR has active subscription
     */
    const hasUsageAccess = computed(() => {
        const isAdminUser = roles.value.includes('admin');
        const hasActiveSubscription = authStore.user?.subscription?.status === 'active' ||
                                      authStore.user?.subscription?.status === 'trialing';
        
        const result = isAdminUser || hasActiveSubscription;
        console.log('hasUsageAccess:', result, 'isAdmin:', isAdminUser, 'hasSubscription:', hasActiveSubscription);
        return result;
    });

    /**
     * Refresh permissions (call after role/permission changes)
     */
    const refreshPermissions = async () => {
        loaded.value = false;
        await fetchPermissions();
    };

    return {
        user,
        roles,
        permissions,
        loading,
        loaded,
        fetchPermissions,
        hasPermission,
        hasAnyPermission,
        hasAllPermissions,
        hasRole,
        hasAnyRole,
        hasAllRoles,
        isAdmin,
        refreshPermissions,
        hasUsageAccess,
    };
}