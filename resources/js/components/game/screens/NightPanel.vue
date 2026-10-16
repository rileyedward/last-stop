<script setup lang="ts">
import { computed, ref } from 'vue';
import { dept } from '@/game/departments';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';
import Sheet from '../Sheet.vue';

const props = defineProps<{ game: GameView }>();
const emit = defineEmits<{
    act: [action: string, data: Record<string, string | boolean>];
    log: [];
}>();

const me = computed(() => props.game.me);
const name = (id: string) =>
    props.game.players.find((p) => p.id === id)?.name ?? '';
const nominating = ref(false);
const chosen = ref<string | null>(null);

const tonight = computed(() => props.game.lastVote);
const candidates = computed(() =>
    props.game.players.filter((p) => !p.isMe && p.nominable),
);
const readyCount = computed(
    () => props.game.players.filter((p) => p.ready).length,
);
const detained = computed(() =>
    props.game.players.filter((p) => p.detained > 0),
);
const lastClue = computed(
    () => props.game.pulls[props.game.pulls.length - 1] ?? null,
);

const nominateLine = computed(() => {
    if (!props.game.nominationsOpen) {
        return 'Someone is already detained. Only one player at a time.';
    }
    if (!me.value) {
        return 'Anyone who is free can nominate a player to detain.';
    }
    if (me.value.detained) {
        return "You're detained, so you can't nominate.";
    }
    if (me.value.escorting) {
        return "You're escorting someone, so you can't nominate.";
    }
    if (me.value.nominatedTonight) {
        return "You've nominated once tonight. Someone else can try.";
    }
    if (candidates.value.length === 0) {
        return 'Nobody can be nominated tonight.';
    }

    return `Name a suspect. If the vote carries they're detained for ${props.game.detainDays} days and you escort them.`;
});

function nominate(): void {
    if (chosen.value) {
        emit('act', 'nominate', { nominee: chosen.value });
        nominating.value = false;
        chosen.value = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-[14px]">
        <div class="flex flex-col gap-[6px]">
            <div class="label">The carriage lights dim</div>
            <h1 class="disp m-0 text-[40px] leading-[.9] font-black">
                Night {{ game.day }} · talk it out
            </h1>
            <div class="text-[15px] leading-[1.4] text-[#b7c3d6]">
                Discussion happens on voice. The app doesn't manage it.
            </div>
        </div>

        <div
            v-if="tonight"
            class="flex flex-col gap-[6px] rounded-[14px] p-4"
            :style="
                tonight.passed
                    ? 'background:#2A0F14;border:2px solid #E5484D'
                    : 'background:#16213A;border:2px solid #3A4A66'
            "
        >
            <div class="disp text-[28px] leading-none font-black">
                {{ tonight.yes }} to {{ tonight.no }}:
                {{ tonight.passed ? 'detained' : 'failed' }}
            </div>
            <div class="text-[14px] leading-[1.4]">
                <template v-if="tonight.passed">
                    {{ name(tonight.nominator) }} escorts
                    {{ name(tonight.nominee) }} for the next
                    {{ game.detainDays }} days. While detained they can't pull
                    the cord.
                </template>
                <template v-else
                    >{{ name(tonight.nominee) }} stays free. It needed a strict
                    majority.</template
                >
            </div>
            <div class="flex flex-wrap gap-[6px] pt-1">
                <span
                    v-for="(yes, id) in tonight.votes"
                    :key="id"
                    class="mono rounded-md px-2 py-1 text-[11px]"
                    :style="
                        yes
                            ? 'background:#E5484D;color:#1A0B0E'
                            : 'background:#2C3D5C;color:#E8EEF5'
                    "
                >
                    {{ name(String(id)) }} {{ yes ? 'YES' : 'NO' }}
                </span>
            </div>
        </div>

        <div
            v-if="detained.length && !tonight"
            class="panel flex flex-col gap-2 p-[14px]"
        >
            <div class="label text-[#ff8e92]">Detained</div>
            <div
                v-for="p in detained"
                :key="p.id"
                class="flex items-center gap-3 text-[14px]"
            >
                <Avatar :name="p.name" :size="26" />
                <span class="flex-1"
                    ><b>{{ p.isMe ? 'You' : p.name }}</b> ·
                    {{ p.detained }} more
                    {{ p.detained === 1 ? 'day' : 'days' }}</span
                >
                <span v-if="p.escortedBy" class="text-[12px] text-[#f4b740]"
                    >escort {{ name(p.escortedBy) }}</span
                >
            </div>
        </div>

        <div
            v-if="lastClue && lastClue.clue.length"
            class="flex flex-col gap-2 rounded-xl border border-[#5a2228] bg-[#1a0f13] p-[14px]"
        >
            <div class="label text-[#ff8e92]">
                Latest cord clue · day {{ lastClue.day }}
            </div>
            <div class="text-[14px] text-[#f6e3e4]">
                One of {{ lastClue.clue.map(name).join(', ') }} pulled it.
            </div>
        </div>

        <div
            class="flex flex-col gap-3 rounded-2xl border border-[#5a2228] bg-[#120a0d] p-4"
        >
            <div
                class="disp text-[28px] leading-[.9] font-black text-[#ff6b70]"
            >
                Detain someone
            </div>
            <div class="text-[14px] leading-[1.35] text-[#f6e3e4]">
                {{ nominateLine }}
            </div>
            <button
                v-if="me?.canNominate && candidates.length"
                type="button"
                class="btn btn-danger"
                @click="nominating = true"
            >
                Nominate a player
            </button>
        </div>

        <button
            type="button"
            class="btn btn-ghost btn-small"
            @click="emit('log')"
        >
            Today's log
        </button>

        <button
            v-if="me"
            type="button"
            class="btn"
            :class="me.ready ? 'btn-outline' : 'btn-ghost'"
            @click="emit('act', 'ready', { ready: !me.ready })"
        >
            {{
                me.ready
                    ? `Ready for dawn · ${readyCount} of ${game.players.length} · tap to undo`
                    : `Ready for dawn · ${readyCount} of ${game.players.length}`
            }}
        </button>

        <Sheet
            :open="nominating"
            title="Name a suspect"
            @close="nominating = false"
        >
            <div class="text-[14px] leading-[1.4] text-[#e3c6c8]">
                Everyone except them votes, all at once, and your vote counts as
                yes. A strict majority detains them; a tie fails.
            </div>
            <div class="flex flex-col gap-2">
                <button
                    v-for="p in candidates"
                    :key="p.id"
                    type="button"
                    class="seat"
                    :style="
                        chosen === p.id
                            ? 'border-color:#E5484D;background:#2A0F14'
                            : ''
                    "
                    :aria-pressed="chosen === p.id"
                    @click="chosen = p.id"
                >
                    <Avatar :name="p.name" />
                    <span class="flex-1 text-[15px] font-bold">{{
                        p.name
                    }}</span>
                    <span
                        class="mono text-[11px]"
                        :style="{ color: dept(p.department).color }"
                        >{{ dept(p.department).code }}</span
                    >
                </button>
            </div>
            <div class="text-[12px] text-[#93a2ba]">
                Detained players, escorts and anyone just released can't be
                named tonight.
            </div>
            <button
                type="button"
                class="btn btn-danger"
                :disabled="!chosen"
                @click="nominate"
            >
                {{ chosen ? `Nominate ${name(chosen)}` : 'Pick a suspect' }}
            </button>
        </Sheet>
    </div>
</template>
