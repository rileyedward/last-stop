<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        score: number;
        color: string;
        crisisAt: number;
        preview?: number;
        small?: boolean;
    }>(),
    { preview: 0, small: false },
);

const SCALE = 10;
const pct = (n: number) =>
    `${(Math.max(0, Math.min(SCALE, n)) / SCALE) * 100}%`;
const crisis = computed(() => pct(props.crisisAt));
const after = computed(() =>
    Math.max(0, Math.min(SCALE, props.score + props.preview)),
);
</script>

<template>
    <div
        class="gauge"
        :class="{ 'gauge-sm': small }"
        :style="{ '--crisis': crisis }"
    >
        <div
            class="gfill"
            :style="{ width: pct(Math.min(score, after)), background: color }"
        />
        <div
            v-if="preview > 0"
            class="ggain"
            :style="{ left: pct(score), width: pct(after - score) }"
        />
        <div
            v-if="preview < 0"
            class="glost"
            :style="{ left: pct(after), width: pct(score - after) }"
        />
    </div>
</template>
