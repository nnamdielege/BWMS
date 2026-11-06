import { router } from '@inertiajs/vue3';

/**
 * Failures that aren't validation errors: a 500, an expired session or lost signal
 * would otherwise look like nothing happened. Handheld screens call this around a
 * save and show the message themselves. Returns a function that stops listening.
 */
export function catchVisitFailures(show: (message: string) => void): () => void {
    const listeners = [
        router.on('invalid', (event) => {
            event.preventDefault();
            const status = event.detail.response.status;
            show(status === 419 ? 'Your session expired, so this was not saved. Reload the page.' : `The server had a problem (${status}), so this was not saved. Try again.`);
        }),
        router.on('exception', (event) => {
            event.preventDefault();
            show('No connection, so this was not saved. Try again.');
        }),
    ];

    return () => listeners.forEach((stop) => stop());
}
