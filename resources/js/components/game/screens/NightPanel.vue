<script setup lang="ts">
import { computed, ref } from 'vue';
import { dept } from '@/game/departments';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';
import Sheet from '../Sheet.vue';
import WeatherCard from '../WeatherCard.vue';

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

const tonight = computed(() =>
    props.game.lastBrake && props.game.lastBrake.day === props.game.day
        ? props.game.lastBrake
        : null,
);
const candidates = computed(() =>
    props.game.players.filter((p) => !p.isMe && p.nominable),
);
const readyCount = computed(
    () => props.game.players.filter((p) => p.ready).length,
);

const brakeLine = computed(() => {
    const b = props.game.brake;
    if (props.game.day < b.fromNight) {
        return `The brake unlocks on night ${b.fromNight}.`;
    }
    if (b.trainLeft === 0) {
        return 'The train has used all its brake pulls.';
    }
    if (b.used) {
        return 'The brake has been pulled tonight. One pull a night.';
    }
    if (!b.open) {
        return 'The brake is resting tonight.';
    }
    if (!me.value) {
        return 'Anyone unrestricted can pull it before the timer ends.';
    }
    if (me.value.restricted) {
        return "You're restricted, so you can't pull the brake.";
    }
    if (me.value.escorting) {
        return "You're escorting someone, so you can't pull the brake.";
    }
    if (me.value.brakesLeft <= 0) {
        return "You've used your pulls.";
    }
    if (candidates.value.length === 0) {
        return 'Nobody can be nominated tonight.';
    }

    return 'Name a suspect. Tomorrow the train loses its base speed, pass or fail.';
});
const canPull = computed(
    () =>
        props.game.brake.open &&
        !props.game.brake.used &&
        !!me.value?.canPull &&
        candidates.value.length > 0,
);
const trainLeft = computed(() =>
    props.game.brake.trainLeft < 0
        ? ''
        : ` · TRAIN: ${props.game.brake.trainLeft} LEFT`,
);

function pull(): void {
    if (chosen.value) {
        emit('act', 'brake', { nominee: chosen.value });
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
            v-if="me?.forecast"
            class="flex flex-col gap-2 rounded-xl border border-[#5a2228] bg-[#1a0b0e] p-[14px]"
        >
            <div class="label text-[#f09a9d]">
                Your forecast · only you see this
            </div>
            <div class="text-[14px] text-[#f6e3e4]">
                Tomorrow's first weather card:
            </div>
            <WeatherCard
                :card="me.forecast"
                row
                :only="[
                    ...game.departments.map((d) => d.key as string),
                    'distance',
                ]"
            />
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
                {{
                    tonight.passed
                        ? 'carried'
                        : tonight.yes === tonight.no
                          ? 'tie fails'
                          : 'failed'
                }}
            </div>
            <div class="text-[14px] leading-[1.4]">
                <template v-if="tonight.passed">
                    {{ name(tonight.puller) }} escorts
                    {{ name(tonight.nominee) }} for the next
                    {{ game.restrictDays }} days. Tomorrow's base speed is 0.
                </template>
                <template v-else
                    >{{ name(tonight.nominee) }} stays free. The brake was still
                    pulled, so tomorrow's base speed is 0 anyway.</template
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
            class="flex items-stretch gap-4 rounded-2xl border border-[#5a2228] bg-[#120a0d] p-4"
        >
            <button
                type="button"
                aria-label="Pull the emergency brake"
                class="flex w-[84px] flex-none flex-col items-center border-none bg-transparent p-0"
                :class="
                    canPull ? 'cursor-pointer' : 'cursor-default opacity-50'
                "
                :disabled="!canPull"
                @click="nominating = true"
            >
                <div
                    class="relative flex h-[150px] w-[84px] justify-center rounded-[14px] border-2 border-[#3a1d22] bg-[#1c1216]"
                >
                    <div
                        class="absolute top-[14px] bottom-[14px] w-3 rounded-md bg-[#070c16]"
                    />
                    <div
                        class="absolute top-[18px] h-14 w-[6px] rounded-[3px] bg-[#8a96a8]"
                    />
                    <div
                        class="hazard absolute top-3 h-[26px] w-[64px] rounded-lg border-2 border-[#ff6b70]"
                    />
                    <div
                        class="mono absolute bottom-[10px] text-[10px] tracking-[.14em] text-[#f09a9d]"
                    >
                        PULL
                    </div>
                </div>
            </button>
            <div class="flex flex-1 flex-col gap-[10px]">
                <div
                    class="disp text-[28px] leading-[.9] font-black text-[#ff6b70]"
                >
                    Emergency brake
                </div>
                <div
                    class="flex flex-col gap-[6px] text-[14px] leading-[1.35] text-[#f6e3e4]"
                >
                    <div>{{ brakeLine }}</div>
                    <div v-if="canPull">
                        If the vote passes, you escort them for
                        {{ game.restrictDays }} days.
                    </div>
                </div>
                <div v-if="me" class="mono text-[12px] text-[#f09a9d]">
                    YOUR PULLS LEFT: {{ me.brakesLeft }}{{ trainLeft }}
                </div>
            </div>
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
                Everyone except them votes, all at once. Strict majority
                restricts them; ties fail.
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
                Restricted players, escorts and anyone just released can't be
                named tonight.
            </div>
            <button
                type="button"
                class="btn btn-danger"
                :disabled="!chosen"
                @click="pull"
            >
                {{
                    chosen
                        ? `Pull the brake on ${name(chosen)}`
                        : 'Pick a suspect'
                }}
            </button>
        </Sheet>
    </div>
</template>
