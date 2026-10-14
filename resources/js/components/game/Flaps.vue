<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        text: string | number;
        size?: 'sm' | 'md' | 'lg';
        pad?: number;
    }>(),
    { size: 'sm', pad: 0 },
);

const chars = computed(() =>
    String(props.text).padStart(props.pad, '0').split(''),
);
</script>

<template>
    <span class="inline-flex gap-[2px]" :aria-label="String(text)">
        <span
            v-for="(ch, i) in chars"
            :key="`${i}-${ch}`"
            class="flap flap-turn"
            :class="{ 'flap-md': size === 'md', 'flap-lg': size === 'lg' }"
            aria-hidden="true"
            >{{ ch }}</span
        >
    </span>
</template>
