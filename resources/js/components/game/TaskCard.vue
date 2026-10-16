<script setup lang="ts">
import { computed } from 'vue';
import { dept, effectList } from '@/game/departments';
import type { CardData } from '@/game/types';

const props = withDefaults(
    defineProps<{
        card: CardData;
        selected?: boolean;
        dimmed?: boolean;
        stamp?: 'vetoed' | 'override' | null;
        tag?: string | null;
        flavor?: boolean;
        big?: boolean;
        only?: string[] | null;
        as?: 'button' | 'div';
    }>(),
    {
        selected: false,
        dimmed: false,
        stamp: null,
        tag: null,
        flavor: true,
        big: false,
        only: null,
        as: 'div',
    },
);

const meta = computed(() => dept(props.card.department));
const fx = computed(() => effectList(props.card.effects, props.only));
</script>

<template>
    <component
        :is="as"
        :type="as === 'button' ? 'button' : undefined"
        class="paper paper-row relative"
        :class="{ 'opacity-70': dimmed }"
        :style="{
            outline: selected ? '3px solid #F4B740' : '0 solid transparent',
            outlineOffset: '3px',
            filter:
                stamp === 'vetoed' ? 'saturate(.35) brightness(.8)' : undefined,
        }"
        :aria-pressed="as === 'button' ? selected : undefined"
    >
        <div :style="{ width: '10px', background: meta.color, flex: 'none' }" />
        <div
            class="flex min-w-0 flex-1 flex-col gap-[6px]"
            :class="big ? 'px-4 py-[14px]' : 'px-[14px] py-3'"
        >
            <div class="flex items-center justify-between gap-2">
                <span class="kind">{{ card.kind }}</span>
                <span
                    v-if="tag"
                    class="mono rounded px-[6px] py-1 text-[10px] tracking-[.14em]"
                    style="background: #1a2230; color: #f4b740"
                    >{{ tag }}</span
                >
            </div>
            <span
                class="disp leading-[.95] font-extrabold"
                :class="big ? 'text-[34px]' : 'text-[25px]'"
                >{{ card.name }}</span
            >
            <span
                v-if="flavor && card.flavor"
                class="text-[13px] text-[#4a5465] italic"
                >{{ card.flavor }}.</span
            >
            <span class="mt-[2px] flex flex-wrap gap-[6px]">
                <span
                    v-for="f in fx"
                    :key="f.key"
                    class="pfx"
                    :class="{ neg: f.value < 0 }"
                    >{{ f.label }}</span
                >
                <span v-if="fx.length === 0" class="pfx">No effect</span>
            </span>
        </div>
        <slot />
        <span v-if="stamp === 'vetoed'" class="stamp">VETOED</span>
        <span
            v-if="stamp === 'override'"
            class="stamp stamp-amber"
            style="transform: translate(-50%, -50%) rotate(8deg)"
            >OVERRIDE</span
        >
    </component>
</template>
