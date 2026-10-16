<script setup lang="ts">
import { dept, signed } from '@/game/departments';
import type { Department } from '@/game/types';
import Gauge from './Gauge.vue';

withDefaults(
    defineProps<{
        departments: Department[];
        crisisAt: number;
        deltas?: Record<string, number>;
        previews?: Record<string, number>;
        max?: number;
        line?: number | null;
    }>(),
    { deltas: () => ({}), previews: () => ({}), max: 10, line: null },
);
</script>

<template>
    <div
        class="grid gap-[10px]"
        :style="{
            gridTemplateColumns: `repeat(${departments.length}, minmax(0, 1fr))`,
        }"
    >
        <div
            v-for="d in departments"
            :key="d.key"
            class="flex flex-col gap-[5px]"
        >
            <div class="flex items-baseline justify-between">
                <span
                    class="mono text-[11px] font-semibold"
                    :style="{ color: dept(d.key).color }"
                    >{{ d.code }}</span
                >
                <span class="mono text-[16px] font-semibold">
                    {{ d.score }}
                    <span
                        v-if="previews[d.key]"
                        class="ml-[3px] text-[11px]"
                        :style="{
                            color: previews[d.key] < 0 ? '#FF8E92' : '#F4B740',
                        }"
                        >{{ signed(previews[d.key]) }}</span
                    >
                    <span
                        v-else-if="deltas[d.key]"
                        class="ml-[3px] text-[11px]"
                        :style="{
                            color: deltas[d.key] < 0 ? '#FF8E92' : '#F4B740',
                        }"
                        >{{ signed(deltas[d.key]) }}</span
                    >
                </span>
            </div>
            <Gauge
                :score="d.score"
                :color="dept(d.key).color"
                :crisis-at="crisisAt"
                :preview="previews[d.key] ?? 0"
                :max="max"
                :line="line"
                small
            />
        </div>
    </div>
</template>
