<script setup lang="ts">
import { computed } from 'vue';
import { effectList } from '@/game/departments';
import type { CardData } from '@/game/types';
import Icon from './Icon.vue';
import { ICONS } from './icons';

const props = withDefaults(
    defineProps<{ card: CardData; only?: string[] | null; row?: boolean }>(),
    {
        only: null,
        row: false,
    },
);

const fx = computed(() =>
    effectList(props.card.effects, props.card.distance, props.only),
);
const calm = computed(
    () => fx.value.length === 0 || fx.value.every((f) => f.value > 0),
);
</script>

<template>
    <div v-if="row" class="wcard flex items-center gap-3 p-3" :class="{ calm }">
        <span :style="{ color: calm ? '#93A2BA' : '#8FD3F0' }"
            ><Icon :paths="calm ? ICONS.sun : ICONS.snow" :size="26"
        /></span>
        <div class="flex flex-1 flex-col gap-[6px]">
            <div class="disp text-[24px] leading-none font-extrabold">
                {{ card.name }}
            </div>
            <div v-if="fx.length" class="flex flex-wrap gap-[5px]">
                <span
                    v-for="f in fx"
                    :key="f.key"
                    class="chip"
                    :class="{ dn: f.value < 0 }"
                    >{{ f.label }}</span
                >
            </div>
            <div v-else class="text-[13px] text-[#b7c3d6] italic">
                {{ card.flavor || 'No effect' }}.
            </div>
        </div>
    </div>
    <div
        v-else
        class="wcard flex min-h-[96px] flex-1 flex-col gap-[6px] p-[10px]"
        :class="{ calm }"
    >
        <span class="disp text-[18px] leading-none font-extrabold">{{
            card.name
        }}</span>
        <span v-if="fx.length" class="mt-auto flex flex-wrap gap-[3px]">
            <span
                v-for="f in fx"
                :key="f.key"
                class="chip"
                :class="{ dn: f.value < 0 }"
                >{{ f.label.replace(' ', '') }}</span
            >
        </span>
        <span v-else class="mt-auto text-[12px] text-[#93a2ba]">No effect</span>
    </div>
</template>
