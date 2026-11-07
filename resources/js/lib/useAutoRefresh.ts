import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

const EVERY_MS = 30_000;

/**
 * Keeps a list screen current: reloads these props every 30s and when the window
 * regains focus. A partial reload keeps component state, so it can't move focus, and
 * `async` means it never cancels something the person just did.
 */
export function useAutoRefresh(only: string[]): void {
    const refresh = () => {
        if (!document.hidden) router.reload({ only, async: true, showProgress: false });
    };
    let timer: number | undefined;

    onMounted(() => {
        timer = window.setInterval(refresh, EVERY_MS);
        window.addEventListener('focus', refresh);
    });
    onBeforeUnmount(() => {
        window.clearInterval(timer);
        window.removeEventListener('focus', refresh);
    });
}
