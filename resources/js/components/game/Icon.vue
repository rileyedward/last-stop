<script setup lang="ts">
withDefaults(defineProps<{ paths: string; size?: number; stroke?: number }>(), {
    size: 22,
    stroke: 2,
});
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        :stroke-width="stroke"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        v-html="paths"
    />
</template>
