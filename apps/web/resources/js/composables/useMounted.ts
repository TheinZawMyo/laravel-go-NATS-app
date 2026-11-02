import { onMounted, readonly, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * `true` once the component has mounted in the browser.
 *
 * Inertia hydrates the first paint from server-rendered HTML, so anything the
 * server cannot know — the Notification permission, the viewer's timezone, the
 * viewer's clock — has to render identically on both sides until hydration
 * finishes. Components gate that markup on this flag: the server and the
 * client's first render agree on the placeholder, and the real value arrives
 * one tick later as an ordinary reactive update instead of a hydration
 * mismatch.
 */
export function useMounted(): Readonly<Ref<boolean>> {
    const mounted = ref(false);

    onMounted(() => {
        mounted.value = true;
    });

    return readonly(mounted);
}
