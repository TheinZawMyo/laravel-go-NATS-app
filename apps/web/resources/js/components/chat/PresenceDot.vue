<script setup lang="ts">
withDefaults(
    defineProps<{
        online: boolean;
        name: string;
        sizeClass?: string;
        borderClass?: string;
    }>(),
    {
        sizeClass: 'size-3.5',
        borderClass: 'border-background',
    },
);
</script>

<template>
    <span
        v-if="online"
        class="absolute right-0 bottom-0 rounded-full border-2 bg-emerald-500 shadow-sm"
        :class="[sizeClass, borderClass]"
        role="img"
        :aria-label="`${name} is online`"
    />
</template>
