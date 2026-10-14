<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        distance: number;
        route: number;
        gain?: number;
        big?: boolean;
    }>(),
    { gain: 0, big: false },
);

const pct = computed(() =>
    Math.min(100, (props.distance / Math.max(1, props.route)) * 100),
);
const gainStart = computed(() =>
    Math.min(
        100,
        (Math.max(0, props.distance - props.gain) / Math.max(1, props.route)) *
            100,
    ),
);
</script>

<template>
    <div
        class="relative"
        :style="{ height: big ? '34px' : '18px' }"
        role="img"
        :aria-label="`${distance} of ${route} travelled`"
    >
        <div
            class="sleepers absolute right-0 left-0"
            :style="{ top: big ? '12px' : '5px', height: big ? '12px' : '8px' }"
        />
        <div
            class="absolute right-0 left-0"
            :style="{
                top: big ? '16px' : '8px',
                height: '2px',
                background: '#5B6C88',
            }"
        />
        <div
            class="absolute left-0"
            :style="{
                top: big ? '14px' : '7px',
                height: big ? '6px' : '4px',
                width: `${pct}%`,
                background: '#8FD3F0',
                transition: 'width .8s ease',
            }"
        />
        <div
            v-if="gain > 0"
            class="absolute"
            :style="{
                top: big ? '14px' : '7px',
                height: big ? '6px' : '4px',
                left: `${gainStart}%`,
                width: `${pct - gainStart}%`,
                background: '#F4B740',
            }"
        />
        <div
            class="absolute flex items-center justify-center"
            :style="{
                top: '0',
                left: `${pct}%`,
                transform: 'translateX(-50%)',
                width: big ? '52px' : '26px',
                height: big ? '34px' : '18px',
                borderRadius: big ? '10px 10px 4px 4px' : '5px 5px 2px 2px',
                background: '#F4B740',
                transition: 'left .8s ease',
                boxShadow: big ? '0 0 40px rgba(244,183,64,.45)' : 'none',
            }"
        >
            <div
                :style="{
                    width: big ? '12px' : '6px',
                    height: big ? '12px' : '6px',
                    borderRadius: '50%',
                    background: '#FFF4D6',
                }"
            />
        </div>
        <div
            class="absolute right-0"
            :style="{
                top: big ? '6px' : '1px',
                width: big ? '22px' : '16px',
                height: big ? '22px' : '16px',
                borderRadius: '50%',
                background: '#E8EEF5',
                border: '3px solid #0E1626',
                boxShadow: '0 0 0 2px #E8EEF5',
            }"
        />
    </div>
</template>
