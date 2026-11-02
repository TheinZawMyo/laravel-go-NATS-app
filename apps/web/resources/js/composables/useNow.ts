import { onBeforeUnmount, onMounted, readonly, ref } from 'vue';

/**
 * A ticking clock for labels that go stale on their own, such as
 * "Last seen 3m ago". Components read `now.value` in a computed so Vue
 * re-renders them when the interval fires instead of every animation frame.
 *
 * The interval only starts on the client: during server rendering nothing
 * unmounts the component, so a timer created in setup would outlive the request
 * and keep the SSR worker alive for good.
 */
export function useNow(intervalMs = 30_000) {
    const now = ref(Date.now());
    let timer: ReturnType<typeof setInterval> | null = null;

    onMounted(() => {
        now.value = Date.now();

        timer = setInterval(() => {
            now.value = Date.now();
        }, intervalMs);
    });

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return readonly(now);
}
