<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { LobbyView, Preset, SettingField } from '@/game/types';
import Sheet from './Sheet.vue';

const props = defineProps<{ open: boolean; lobby: LobbyView }>();
const emit = defineEmits<{
    close: [];
    preset: [preset: string];
    custom: [values: Record<string, number>];
}>();

const secs = (n: number) =>
    n >= 60
        ? `${Math.floor(n / 60)}:${String(n % 60).padStart(2, '0')}`
        : `${n}s`;

const rules = computed(() => props.lobby.setup.rules);
const draft = ref<Record<string, number>>({ ...rules.value });
watch(
    rules,
    (r) => {
        draft.value = { ...r };
    },
    { deep: true },
);

const groups = computed(() => {
    const out: { title: string; fields: SettingField[] }[] = [];
    for (const f of props.lobby.fields) {
        const last = out[out.length - 1];
        if (last && last.title === f.group) {
            last.fields.push(f);
        } else {
            out.push({ title: f.group, fields: [f] });
        }
    }

    return out;
});

const colors: Record<string, string> = {
    'The line': '#8FD3F0',
    Hands: '#C39BEB',
    'The cord': '#FF8E92',
    Detaining: '#FF8E92',
    Winning: '#F4B740',
    Timers: '#F4B740',
};

const dirty = computed(() =>
    props.lobby.fields.some((f) => draft.value[f.key] !== rules.value[f.key]),
);
const changedFromStandard = (key: string) =>
    draft.value[key] !== props.lobby.setup.standard[key];

function step(f: SettingField, by: number): void {
    const unit = f.kind === 'seconds' ? 5 : 1;
    const value = (draft.value[f.key] ?? 0) + by * unit;
    draft.value[f.key] = Math.max(f.min, Math.min(f.max, value));
}

function toggle(f: SettingField): void {
    draft.value[f.key] = draft.value[f.key] ? 0 : 1;
}

function show(f: SettingField, value: number | undefined): string {
    if (f.kind === 'switch') {
        return value ? 'ON' : 'OFF';
    }

    return f.kind === 'seconds' ? secs(value ?? 0) : String(value ?? 0);
}

const presets: { key: Preset; label: string }[] = [
    { key: 'easy', label: 'Easy' },
    { key: 'standard', label: 'Standard' },
    { key: 'hard', label: 'Hard' },
    { key: 'custom', label: 'Custom' },
];
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
                :key="p.key"
                type="button"
                class="mono h-11 flex-1 text-[11px] tracking-[.08em] uppercase"
                :aria-pressed="lobby.preset === p.key"
                :disabled="!lobby.isHost"
                :style="{
                    background: lobby.preset === p.key ? '#F4B740' : '#0B1220',
                    color: lobby.preset === p.key ? '#1A1200' : '#B7C3D6',
                }"
                @click="emit('preset', p.key)"
            >
                {{ p.label }}
            </button>
        </div>
        <div class="text-[12px] leading-[1.45] text-[#93a2ba]">
            Easy and Hard change only the days ({{
                lobby.setup.presetDays.easy
            }}
            / {{ lobby.setup.presetDays.standard }} /
            {{ lobby.setup.presetDays.hard }} at this table size). Custom is
            Standard plus whatever you change below.
        </div>

        <div>
            <div v-for="g in groups" :key="g.title" class="pt-[14px]">
                <div class="label" :style="{ color: colors[g.title] }">
                    {{ g.title }}
                </div>
                <div
                    v-for="f in g.fields"
                    :key="f.key"
                    class="flex min-h-[46px] items-center gap-[10px] border-b border-[#18233a] py-[6px]"
                >
                    <span class="flex flex-1 flex-col">
                        <span class="text-[15px]">{{ f.label }}</span>
                        <span
                            v-if="f.help"
                            class="text-[12px] text-[#93a2ba]"
                            >{{ f.help }}</span
                        >
                    </span>
                    <template v-if="lobby.isHost && f.kind === 'switch'">
                        <button
                            type="button"
                            class="mono h-9 w-[70px] rounded-lg text-[13px] font-semibold"
                            :aria-pressed="draft[f.key] === 1"
                            :aria-label="f.label"
                            :style="{
                                background: draft[f.key]
                                    ? '#2A2110'
                                    : '#111A2B',
                                border: `1px solid ${draft[f.key] ? '#F4B740' : '#2C3D5C'}`,
                                color: draft[f.key] ? '#F4B740' : '#93A2BA',
                            }"
                            @click="toggle(f)"
                        >
                            {{ draft[f.key] ? 'ON' : 'OFF' }}
                        </button>
                    </template>
                    <template v-else-if="lobby.isHost">
                        <button
                            type="button"
                            class="h-9 w-9 rounded-lg bg-[#16213a] text-[18px] text-[#b7c3d6]"
                            :aria-label="`Less ${f.label}`"
                            :disabled="(draft[f.key] ?? 0) <= f.min"
                            @click="step(f, -1)"
                        >
                            −
                        </button>
                        <span
                            class="mono w-[52px] text-center text-[18px] font-semibold"
                            :class="{
                                'text-[#f4b740]': changedFromStandard(f.key),
                            }"
                            >{{ show(f, draft[f.key]) }}</span
                        >
                        <button
                            type="button"
                            class="h-9 w-9 rounded-lg bg-[#16213a] text-[18px] text-[#b7c3d6]"
                            :aria-label="`More ${f.label}`"
                            :disabled="(draft[f.key] ?? 0) >= f.max"
                            @click="step(f, 1)"
                        >
                            +
                        </button>
                    </template>
                    <span
                        v-else
                        class="mono w-[70px] text-right text-[18px] font-semibold"
                        :class="{
                            'text-[#f4b740]': changedFromStandard(f.key),
                        }"
                        >{{ show(f, rules[f.key]) }}</span
                    >
                </div>
            </div>
        </div>

        <div
            v-if="lobby.isHost && dirty"
            class="sticky bottom-0 flex gap-[10px] bg-[#0e1626] pt-2"
        >
            <button
                type="button"
                class="btn btn-ghost flex-1"
                @click="draft = { ...rules }"
            >
                Undo
            </button>
            <button
                type="button"
                class="btn btn-primary flex-1"
                @click="emit('custom', { ...draft })"
            >
                Save as Custom
            </button>
        </div>
        <div class="text-center text-[13px] text-[#b7c3d6]">
            {{
                lobby.isHost
                    ? 'Any change clears everyone’s Ready. Gold numbers differ from Standard.'
                    : `Only ${lobby.hostName}, the host, can change these. Gold numbers differ from Standard.`
            }}
        </div>
    </Sheet>
</template>
