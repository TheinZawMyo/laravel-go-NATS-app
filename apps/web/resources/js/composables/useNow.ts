import { onBeforeUnmount, readonly, ref } from 'vue';

/**
 * A ticking clock for labels that go stale on their own, such as
 * "Last seen 3m ago". Components read `now.value` in a computed so Vue
 * re-renders them when the interval fires instead of every animation frame.
 */
export function useNow(intervalMs = 30_000) {
    const now = ref(Date.now());

    const timer = setInterval(() => {
        now.value = Date.now();
    }, intervalMs);

    onBeforeUnmount(() => {
        clearInterval(timer);
    });

    return readonly(now);
}
