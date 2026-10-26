import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * UI-only permission check, used to hide controls. The server enforces every
 * permission independently; never rely on this for security.
 */
export function useCan() {
    const page = usePage<SharedData>();

    return (permission: string): boolean => page.props.auth.permissions.includes(permission);
}
