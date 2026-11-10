<script setup lang="ts">
import { ref } from 'vue';
import { useEventListener } from '@vueuse/core';
import { UserPlus, Users, X } from '@lucide/vue';
import NewGroupPanel from '@/components/chat/NewGroupPanel.vue';
import PeoplePanel from '@/components/chat/PeoplePanel.vue';
import { Button } from '@/components/ui/button';
import { useMounted } from '@/composables/useMounted';
import { useContactsStore } from '@/stores/contacts';

type NavTab = 'people' | 'group';

const contacts = useContactsStore();
const mounted = useMounted();

const open = ref(false);
const tab = ref<NavTab>('people');

function show(next: NavTab): void {
    tab.value = next;
    open.value = true;
}

function close(): void {
    open.value = false;
}

useEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape' && open.value) {
        close();
    }
});
</script>

<template>
    <nav
        aria-label="People and groups"
        class="fixed bottom-5 left-1/2 z-30 flex -translate-x-1/2 items-center gap-1 rounded-full border border-border/70 bg-background/90 p-1.5 shadow-xl shadow-black/15 backdrop-blur-xl md:bottom-4 md:left-[11rem] md:z-10 lg:left-[12.5rem]"
    >
        <Button
            type="button"
            variant="ghost"
            class="relative h-10 rounded-full px-4 text-muted-foreground hover:bg-muted/70 hover:text-foreground"
            aria-label="People"
            @click="show('people')"
        >
            <Users class="size-4" />
            <span class="text-xs font-semibold">People</span>
            <span
                v-if="contacts.pendingIncomingCount > 0"
                class="ml-0.5 inline-flex min-w-5 items-center justify-center rounded-full bg-chat-accent px-1.5 py-0.5 text-[10px] leading-none font-bold text-white"
            >{{ contacts.pendingIncomingCount }}</span>
        </Button>
        <Button
            type="button"
            variant="ghost"
            class="h-10 rounded-full px-4 text-muted-foreground hover:bg-muted/70 hover:text-foreground"
            aria-label="New group"
            @click="show('group')"
        >
            <UserPlus class="size-4" />
            <span class="text-xs font-semibold">New group</span>
        </Button>
    </nav>

    <Teleport v-if="mounted" to="body">
        <div v-if="open">
            <div
                class="fixed inset-0 z-40 bg-black/30 backdrop-blur-[1px]"
                aria-hidden="true"
                @click="close"
            />

            <section
                role="dialog"
                aria-modal="true"
                aria-label="People and groups"
                class="fixed bottom-20 left-1/2 z-50 flex max-h-[min(72vh,38rem)] w-[min(24rem,calc(100vw-1.5rem))] -translate-x-1/2 flex-col overflow-hidden rounded-2xl border border-black/5 bg-telegram-panel shadow-2xl shadow-black/20 md:bottom-20 md:left-[11rem] md:translate-x-0 lg:left-[12.5rem] dark:border-white/10"
            >
                <header
                    class="flex shrink-0 items-center justify-between gap-2 border-b border-border/60 px-3 py-2.5"
                >
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                            :class="
                                tab === 'people'
                                    ? 'bg-chat-accent text-white shadow-sm'
                                    : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground'
                            "
                            :aria-pressed="tab === 'people'"
                            @click="tab = 'people'"
                        >
                            <Users class="size-3.5" />
                            People
                            <span
                                v-if="contacts.pendingIncomingCount > 0"
                                class="rounded-full px-1.5 text-[10px] leading-4"
                                :class="
                                    tab === 'people'
                                        ? 'bg-white/20 text-white'
                                        : 'bg-muted text-muted-foreground'
                                "
                            >
                                {{ contacts.pendingIncomingCount }}
                            </span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                            :class="
                                tab === 'group'
                                    ? 'bg-chat-accent text-white shadow-sm'
                                    : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground'
                            "
                            :aria-pressed="tab === 'group'"
                            @click="tab = 'group'"
                        >
                            <UserPlus class="size-3.5" />
                            New group
                        </button>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 text-muted-foreground"
                        aria-label="Close people panel"
                        @click="close"
                    >
                        <X />
                    </Button>
                </header>

                <div class="flex min-h-0 flex-1 flex-col overflow-hidden p-3">
                    <PeoplePanel v-if="tab === 'people'" @close="close" />
                    <NewGroupPanel v-else @created="close" />
                </div>
            </section>
        </div>
    </Teleport>
</template>
