import { onUnmounted, ref } from 'vue';

/**
 * Periodic polling — while a parse runs in the background, the interface needs
 * somewhere to learn about progress from.
 *
 * Polling rather than websockets is a deliberate choice: it needs no extra
 * service on the host, and the status only has to be checked every couple of
 * seconds and only while the user is looking at the page. The timer is always
 * cleared on unmount, otherwise requests would keep firing after navigation.
 */
export function usePolling(callback, intervalMs = 2000) {
    const timer = ref(null);
    const isPolling = ref(false);

    function stop() {
        if (timer.value !== null) {
            clearInterval(timer.value);
            timer.value = null;
        }

        isPolling.value = false;
    }

    function start() {
        if (timer.value !== null) {
            return;
        }

        isPolling.value = true;
        timer.value = setInterval(async () => {
            const shouldContinue = await callback();

            if (shouldContinue === false) {
                stop();
            }
        }, intervalMs);
    }

    onUnmounted(stop);

    return { start, stop, isPolling };
}
