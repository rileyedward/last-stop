<script setup lang="ts">
import { initial } from '@/game/departments';

withDefaults(
    defineProps<{
        name: string;
        me?: boolean;
        muted?: boolean;
        size?: number;
    }>(),
    {
        me: false,
        muted: false,
        size: 30,
    },
);
</script>

<template>
    <span
        class="av"
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            background: muted ? '#16213A' : me ? '#F4B740' : '#E8EEF5',
            color: muted ? '#93A2BA' : '#0B1220',
            border: muted ? '1px dashed #3A4A66' : undefined,
            fontSize: `${Math.round(size * 0.43)}px`,
        }"
        >{{ initial(name) }}</span
    >
</template>
