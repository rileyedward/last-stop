<script setup lang="ts">
import { computed } from 'vue';
import { dept } from '@/game/departments';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';

const props = defineProps<{ game: GameView }>();

const name = (id: string | null) =>
    props.game.players.find((p) => p.id === id)?.name ?? '';
const done = computed(() =>
    props.game.phase === 'day'
        ? props.game.players.filter((p) => p.status === 'picked').length
        : props.game.players.filter((p) => p.ready).length,
);

function badge(p: GameView['players'][number]): {
    text: string;
    color: string;
} {
    if (p.absent) {
        return { text: 'AUTO', color: '#93A2BA' };
    }
    if (props.game.phase === 'day') {
        return (
            {
                picked: { text: 'LOCKED', color: '#F4B740' },
                escort: { text: 'WITH ESCORT', color: '#FF8E92' },
                vetoed: { text: 'VETOED', color: '#FF8E92' },
                thinking: { text: 'THINKING…', color: '#93A2BA' },
                idle: { text: '', color: '#93A2BA' },
            } as const
        )[p.status];
    }
    if (props.game.phase === 'arrival') {
        return p.accused
            ? { text: 'VOTED', color: '#F4B740' }
            : { text: '', color: '#93A2BA' };
    }
    if (props.game.phase === 'vote') {
        return p.voted
            ? { text: 'VOTED', color: '#F4B740' }
            : { text: '', color: '#93A2BA' };
    }

    return p.ready
        ? { text: 'READY', color: '#F4B740' }
        : { text: '', color: '#93A2BA' };
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
            <div class="label text-[#f4b740]">
                {{
                    game.phase === 'day'
                        ? `Locked in · ${done} of ${game.players.length}`
                        : `${done} of ${game.players.length} ready`
                }}
            </div>
            <h1 class="disp m-0 text-[34px] leading-[.9] font-black">
                The crew
            </h1>
        </div>
        <div class="flex flex-col gap-2">
            <div
                v-for="p in game.players"
                :key="p.id"
                class="flex items-center gap-3 rounded-xl border bg-[#111a2b] px-3 py-[10px]"
                :style="{
                    borderColor: p.isMe ? dept(p.department).color : '#24324A',
                    borderStyle: p.status === 'thinking' ? 'dashed' : 'solid',
                    opacity: p.absent ? 0.6 : 1,
                }"
            >
                <Avatar
                    :name="p.name"
                    :me="p.isMe"
                    :muted="p.status === 'thinking'"
                />
                <div class="flex min-w-0 flex-1 flex-col gap-[3px]">
                    <b class="text-[15px]">{{ p.isMe ? 'You' : p.name }}</b>
                    <span
                        class="mono text-[10px] tracking-[.12em]"
                        :style="{ color: dept(p.department).color }"
                        >{{ dept(p.department).short.toUpperCase() }}</span
                    >
                    <span v-if="p.detained" class="text-[12px] text-[#ff8e92]">
                        Detained · {{ p.detained }}
                        {{ p.detained === 1 ? 'day' : 'days' }} left{{
                            p.escortedBy
                                ? ` · escort ${name(p.escortedBy)}`
                                : ''
                        }}
                    </span>
                    <span
                        v-else-if="p.escortOf"
                        class="text-[12px] text-[#f4b740]"
                        >Escorting {{ name(p.escortOf) }}</span
                    >
                    <span
                        v-else-if="p.cooldown"
                        class="text-[12px] text-[#93a2ba]"
                        >Can't be nominated tonight</span
                    >
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span
                        class="mono text-[11px] tracking-[.1em]"
                        :style="{ color: badge(p).color }"
                        >{{ badge(p).text }}</span
                    >
                    <span
                        v-if="p.overridesLeft"
                        class="mono text-[10px] text-[#93a2ba]"
                        :title="`${p.overridesLeft} veto overrides left`"
                        >OVERRIDE ×{{ p.overridesLeft }}</span
                    >
                </div>
            </div>
        </div>
        <div class="text-[13px] leading-[1.45] text-[#93a2ba]">
            The train has {{ game.cordLeft }} of {{ game.cordPulls }} cord pulls
            left. Players in one department share a deck, so they can never hold
            the same card on the same day.
        </div>
    </div>
</template>
