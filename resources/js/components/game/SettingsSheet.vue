<script setup lang="ts">
import { computed } from 'vue';
import type { LobbyView } from '@/game/types';
import Sheet from './Sheet.vue';

const props = defineProps<{ open: boolean; lobby: LobbyView }>();
const emit = defineEmits<{ close: []; preset: [preset: string] }>();

const secs = (n: number) =>
    n >= 60
        ? `${Math.floor(n / 60)}:${String(n % 60).padStart(2, '0')}`
        : `${n}s`;

const groups = computed(() => {
    const s = props.lobby.setup;

    return [
        {
            title: 'The trip',
            color: '#8FD3F0',
            rows: [
                ['Route distance', s.route],
                ['Days', s.days],
                ['Weather cards each dawn', s.weather],
            ],
        },
        {
            title: 'The traitor',
            color: '#FF8E92',
            rows: [
                ['Traitors', s.traitors],
                ['Nightly forecast', s.forecast ? 'ON' : 'OFF'],
            ],
        },
        {
            title: 'The brake',
            color: '#FF8E92',
            rows: [
                ['Brake unlocks on night', s.brakeFrom],
                ['Pulls per player', s.brakesPerPlayer],
                ['Pulls per train', s.brakesPerGame || 'No cap'],
                ['Restriction lasts (days)', s.restrictDays],
            ],
        },
        {
            title: 'Departments',
            color: '#C39BEB',
            rows: [
                ['Starting score', s.startScore],
                ['Crisis at or below', s.crisisAt],
            ],
        },
        {
            title: 'Clocks',
            color: '#F4B740',
            rows: [
                ['Day timer', secs(s.daySeconds)],
                ['Night timer', secs(s.nightSeconds)],
            ],
        },
    ];
});

const presets = ['easy', 'standard', 'hard'] as const;
</script>

<template>
    <Sheet :open="open" title="Trip settings" @close="emit('close')">
        <div class="flex items-center justify-between">
            <span class="label"
                >Rulebook table for {{ lobby.setup.players }} players</span
            >
        </div>
        <div
            class="flex overflow-hidden rounded-[10px] border border-[#2c3d5c]"
            role="group"
            aria-label="Preset"
        >
            <button
                v-for="p in presets"
                :key="p"
                type="button"
                class="mono h-11 flex-1 text-[12px] tracking-[.08em] uppercase"
                :aria-pressed="lobby.preset === p"
                :disabled="!lobby.isHost"
                :style="{
                    background: lobby.preset === p ? '#F4B740' : '#0B1220',
                    color: lobby.preset === p ? '#1A1200' : '#B7C3D6',
                }"
                @click="emit('preset', p)"
            >
                {{ p }}
            </button>
        </div>
        <div>
            <div v-for="g in groups" :key="g.title" class="pt-[14px]">
                <div class="label" :style="{ color: g.color }">
                    {{ g.title }}
                </div>
                <div
                    v-for="[label, value] in g.rows"
                    :key="label"
                    class="flex min-h-[46px] items-center gap-[10px] border-b border-[#18233a] py-[6px]"
                >
                    <span class="flex-1 text-[15px]">{{ label }}</span>
                    <span
                        class="mono w-[70px] text-right text-[18px] font-semibold"
                        >{{ value }}</span
                    >
                </div>
            </div>
        </div>
        <div class="text-[12px] leading-[1.45] text-[#93a2ba]">
            Easy and Hard change only the route. Numbers come from the rulebook
            and update with the balance simulator. Route, days and weather
            follow the number of passengers.
        </div>
        <div class="text-center text-[13px] text-[#b7c3d6]">
            {{
                lobby.isHost
                    ? 'Changing the preset clears everyone’s Ready.'
                    : `Only ${lobby.hostName}, the host, can change these.`
            }}
        </div>
    </Sheet>
</template>
