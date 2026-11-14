import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

const EVERY_MS = 30_000;

type Options = {
    /** Skip a beat, e.g. while a save is in flight, so a reload can't land after it with older data. */
    paused?: () => boolean;
    /** Runs after each reload, to merge fresh props into local state. */
    onSuccess?: () => void;
};

/**
 * Keeps a screen current: reloads these props every 30s and when the window
 * regains focus. A partial reload keeps component state, so it can't move focus
 * (a scan box keeps it), and `async` means it never cancels something the person just did.
 */
export function useAutoRefresh(only: string[], options: Options = {}): void {
    const refresh = () => {
        if (document.hidden || options.paused?.()) return;
        router.reload({ only, async: true, showProgress: false, onSuccess: () => options.onSuccess?.() });
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
