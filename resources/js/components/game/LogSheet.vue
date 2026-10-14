<script setup lang="ts">
import { computed, ref } from 'vue';
import { effectList } from '@/game/departments';
import type { LogEntry } from '@/game/types';
import Sheet from './Sheet.vue';

const props = defineProps<{ open: boolean; log: LogEntry[] }>();
const emit = defineEmits<{ close: [] }>();

const filter = ref<'ALL' | 'WEATHER' | 'PICKS' | 'BRAKE'>('ALL');
const filters = ['ALL', 'WEATHER', 'PICKS', 'BRAKE'] as const;

const kinds: Record<string, string[]> = {
    WEATHER: ['weather'],
    PICKS: ['pick', 'override', 'veto', 'result'],
    BRAKE: ['brake', 'vote', 'veto'],
};

const days = computed(() => {
    const entries = props.log
        .filter(
            (e) =>
                filter.value === 'ALL' || kinds[filter.value].includes(e.kind),
        )
        .slice()
        .reverse();
    const groups: { day: number; entries: LogEntry[] }[] = [];
    for (const entry of entries) {
        const last = groups[groups.length - 1];
        if (last && last.day === entry.day) {
            last.entries.push(entry);
        } else {
            groups.push({ day: entry.day, entries: [entry] });
        }
    }

    return groups;
});

const icon = (kind: string) =>
    ({
        weather: { icon: '*', bg: '#12233A', fg: '#8FD3F0' },
        pick: { icon: '▲', bg: '#2A2110', fg: '#F4B740' },
        override: { icon: '!', bg: '#2A2110', fg: '#F4B740' },
        veto: { icon: '✕', bg: '#2A0F14', fg: '#FF8E92' },
        brake: { icon: '■', bg: '#2A0F14', fg: '#FF8E92' },
        vote: { icon: '✓', bg: '#2A0F14', fg: '#FF8E92' },
        result: { icon: '=', bg: '#0F2A33', fg: '#8FD3F0' },
        night: { icon: '☾', bg: '#16213A', fg: '#E8EEF5' },
    })[kind] ?? { icon: '·', bg: '#16213A', fg: '#93A2BA' };
</script>

<template>
    <Sheet :open="open" title="Log" @close="emit('close')">
        <div class="flex flex-wrap gap-[6px]">
            <button
                v-for="f in filters"
                :key="f"
                type="button"
                class="mono h-9 rounded-lg px-3 text-[11px] tracking-[.1em]"
                :aria-pressed="filter === f"
                :style="{
                    background: filter === f ? '#F4B740' : '#111A2B',
                    color: filter === f ? '#1A1200' : '#B7C3D6',
                    border: `1px solid ${filter === f ? '#F4B740' : '#2C3D5C'}`,
                }"
                @click="filter = f"
            >
                {{ f }}
            </button>
        </div>
        <div class="flex flex-col">
            <template v-for="group in days" :key="group.day">
                <div class="label py-[6px] text-[#e8eef5]">
                    {{ group.day === 0 ? 'Departure' : `Day ${group.day}` }}
                </div>
                <div
                    v-for="(e, i) in group.entries"
                    :key="i"
                    class="flex items-start gap-[10px] border-b border-[#18233a] py-[10px]"
                >
                    <span
                        class="mono flex h-[22px] w-[22px] flex-none items-center justify-center rounded-md text-[11px] font-bold"
                        :style="{
                            background: icon(e.kind).bg,
                            color: icon(e.kind).fg,
                        }"
                        >{{ icon(e.kind).icon }}</span
                    >
                    <div class="flex min-w-0 flex-col gap-[5px]">
                        <span class="text-[14px] leading-[1.3]">{{
                            e.kind === 'weather' ? `${e.text} at dawn` : e.text
                        }}</span>
                        <span
                            v-if="e.fx && Object.keys(e.fx).length"
                            class="flex flex-wrap gap-1"
                        >
                            <span
                                v-for="x in effectList(e.fx, 0)"
                                :key="x.key"
                                class="chip"
                                :class="{ dn: x.value < 0 }"
                                >{{
                                    x.key === 'distance'
                                        ? `DIST ${x.value > 0 ? '+' : '−'}${Math.abs(x.value)}`
                                        : x.label
                                }}</span
                            >
                        </span>
                    </div>
                </div>
            </template>
            <div
                v-if="days.length === 0"
                class="py-4 text-[14px] text-[#93a2ba]"
            >
                {{
                    filter === 'BRAKE'
                        ? 'Nobody has pulled the brake yet this trip.'
                        : 'Nothing here yet.'
                }}
            </div>
        </div>
    </Sheet>
</template>
