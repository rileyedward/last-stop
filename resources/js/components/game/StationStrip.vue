<script setup lang="ts">
import { computed } from 'vue';
import type { Station } from '@/game/types';

const props = withDefaults(
    defineProps<{
        stations: Station[];
        leg: number;
        big?: boolean;
        names?: boolean;
    }>(),
    { big: false, names: false },
);

const last = computed(() => Math.max(1, props.stations.length - 1));
const pos = (i: number) => `${(i / last.value) * 100}%`;
const here = computed(() => props.stations[props.leg]?.name ?? '');
</script>

<template>
    <div class="flex flex-col gap-[6px]">
        <div
            class="relative"
            :style="{ height: big ? '34px' : '18px' }"
            role="img"
            :aria-label="`At ${here}, station ${leg} of ${stations.length - 1}`"
        >
            <div
                class="sleepers absolute right-0 left-0"
                :style="{
                    top: big ? '12px' : '5px',
                    height: big ? '12px' : '8px',
                }"
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
                    width: pos(leg),
                    background: '#8FD3F0',
                    transition: 'width .8s ease',
                }"
            />
            <div
                v-for="(s, i) in stations"
                :key="i"
                class="absolute rounded-full"
                :style="{
                    left: pos(i),
                    top: big ? '9px' : '3px',
                    width: big ? '16px' : '12px',
                    height: big ? '16px' : '12px',
                    transform: 'translateX(-50%)',
                    background: i <= leg ? '#8FD3F0' : '#0E1626',
                    border: `${big ? 3 : 2}px solid ${i === stations.length - 1 ? '#E8EEF5' : i <= leg ? '#8FD3F0' : '#5B6C88'}`,
                }"
                :title="s.name"
            />
            <div
                class="absolute flex items-center justify-center"
                :style="{
                    top: '0',
                    left: pos(leg),
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
        </div>
        <div
            v-if="names"
            class="mono relative flex justify-between text-[10px] tracking-[.08em] text-[#93a2ba]"
            :class="big ? 'text-[13px]' : ''"
        >
            <span
                v-for="(s, i) in stations"
                :key="i"
                class="w-0 text-center whitespace-nowrap"
                :class="{
                    'text-[#f4b740]': i === leg,
                    'self-start': true,
                }"
                :style="{
                    transform:
                        i === 0
                            ? 'translateX(0)'
                            : i === stations.length - 1
                              ? 'translateX(-100%)'
                              : 'translateX(-50%)',
                    textAlign:
                        i === 0
                            ? 'left'
                            : i === stations.length - 1
                              ? 'right'
                              : 'center',
                    visibility:
                        big || i === 0 || i === leg || i === stations.length - 1
                            ? 'visible'
                            : 'hidden',
                }"
                >{{ s.name.toUpperCase() }}</span
            >
        </div>
    </div>
</template>
