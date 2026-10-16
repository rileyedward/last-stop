<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminFrame from '@/components/admin/AdminFrame.vue';
import type { SettingField } from '@/game/types';

type Row = Record<string, number | string | string[]> & {
    players: number;
    departments: string[];
};

const props = defineProps<{
    fields: SettingField[];
    tableFields: SettingField[];
    values: Record<string, number>;
    table: Row[];
    presets: { easy: number; hard: number };
    edited: string[];
}>();

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

const values = ref<Record<string, number>>({ ...props.values });
const table = ref<Row[]>(props.table.map((r) => ({ ...r })));
const presets = ref({ ...props.presets });
watch(
    () => [props.values, props.table, props.presets],
    () => {
        values.value = { ...props.values };
        table.value = props.table.map((r) => ({ ...r }));
        presets.value = { ...props.presets };
    },
);

const groups = computed(() => {
    const out: { title: string; fields: SettingField[] }[] = [];
    for (const f of props.fields) {
        const last = out[out.length - 1];
        if (last && last.title === f.group) {
            last.fields.push(f);
        } else {
            out.push({ title: f.group, fields: [f] });
        }
    }

    return out;
});

const saving = ref(false);
const confirmReset = ref(false);

function save(): void {
    saving.value = true;
    router.post(
        '/admin/rules',
        {
            values: values.value,
            table: table.value.map((row) => {
                const out: Record<string, number> = { players: row.players };
                for (const f of props.tableFields) {
                    out[f.key] = Number(row[f.key]);
                }

                return out;
            }),
            presets: presets.value,
        },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}

function reset(): void {
    confirmReset.value = false;
    router.post('/admin/rules/reset', {}, { preserveScroll: true });
}

const short: Record<string, string> = {
    traitors: 'Traitors',
    legs: 'Legs',
    days: 'Days',
    weather_per_day: 'Weather',
    start_score: 'Start',
    line_start: 'Line',
    cord_clue: 'Clue',
};
</script>

<template>
    <AdminFrame title="Rules" active="rules">
        <div class="text-[15px] leading-[1.45] text-[#b7c3d6]">
            The defaults every new lobby starts with. Hosts can still override
            any of them for their own lobby. Games already running keep the
            rules they started with.
            <template v-if="edited.length">
                Changed from the shipped defaults: {{ edited.join(', ') }}.
            </template>
        </div>

        <div
            v-if="errors.rules"
            class="notice-red px-[14px] py-3 text-[14px]"
            role="alert"
        >
            {{ errors.rules }}
        </div>

        <section class="panel flex flex-col gap-3 p-[14px]">
            <div class="label text-[#8fd3f0]">Per player count</div>
            <div class="overflow-x-auto">
                <table class="mono w-full min-w-[640px] border-collapse">
                    <thead>
                        <tr
                            class="text-left text-[11px] tracking-[.1em] text-[#93a2ba]"
                        >
                            <th class="py-2 pr-2">PLAYERS</th>
                            <th
                                v-for="f in tableFields"
                                :key="f.key"
                                class="py-2 pr-2"
                                :title="f.label"
                            >
                                {{ (short[f.key] ?? f.label).toUpperCase() }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, i) in table"
                            :key="row.players"
                            class="border-t border-[#18233a]"
                        >
                            <td
                                class="py-2 pr-2 text-[18px] font-semibold"
                                :title="row.departments.join(', ')"
                            >
                                {{ row.players }}
                            </td>
                            <td
                                v-for="f in tableFields"
                                :key="f.key"
                                class="py-2 pr-2"
                            >
                                <input
                                    v-model.number="row[f.key]"
                                    type="number"
                                    :min="f.min"
                                    :max="f.max"
                                    :aria-label="`${f.label} at ${row.players} players`"
                                    class="h-10 w-[64px] rounded-lg border bg-[#0b1220] px-2 text-[16px] text-[#e8eef5]"
                                    :class="
                                        errors[`table.${i}.${f.key}`]
                                            ? 'border-[#e5484d]'
                                            : 'border-[#2c3d5c]'
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-for="(message, key) in errors"
                :key="key"
                class="text-[13px] text-[#ff8e92]"
            >
                <template v-if="String(key).startsWith('table.')">{{
                    message
                }}</template>
            </div>
            <div class="text-[13px] text-[#93a2ba]">
                Departments per count stay as shipped (3 at 4 players, all 4
                above).
            </div>
        </section>

        <section class="panel flex flex-col gap-3 p-[14px]">
            <div class="label text-[#f4b740]">Presets · days added</div>
            <div class="flex flex-wrap gap-6">
                <label
                    v-for="p in ['easy', 'hard'] as const"
                    :key="p"
                    class="flex items-center gap-3"
                >
                    <span class="text-[15px] capitalize">{{ p }}</span>
                    <input
                        v-model.number="presets[p]"
                        type="number"
                        min="-5"
                        max="5"
                        class="h-10 w-[72px] rounded-lg border border-[#2c3d5c] bg-[#0b1220] px-2 text-[16px] text-[#e8eef5]"
                    />
                    <span
                        v-if="errors[`presets.${p}`]"
                        class="text-[13px] text-[#ff8e92]"
                        >{{ errors[`presets.${p}`] }}</span
                    >
                </label>
            </div>
        </section>

        <section
            v-for="g in groups"
            :key="g.title"
            class="panel flex flex-col gap-1 p-[14px]"
        >
            <div class="label">{{ g.title }}</div>
            <label
                v-for="f in g.fields"
                :key="f.key"
                class="flex min-h-[48px] items-center gap-3 border-b border-[#18233a] py-1 last:border-b-0"
            >
                <span class="flex flex-1 flex-col">
                    <span class="text-[15px]">{{ f.label }}</span>
                    <span
                        v-if="errors[f.key]"
                        class="text-[13px] text-[#ff8e92]"
                        >{{ errors[f.key] }}</span
                    >
                    <span
                        v-else-if="f.help"
                        class="text-[12px] text-[#93a2ba]"
                        >{{ f.help }}</span
                    >
                </span>
                <button
                    v-if="f.kind === 'switch'"
                    type="button"
                    class="mono h-10 w-[84px] rounded-lg text-[13px] font-semibold"
                    :aria-pressed="values[f.key] === 1"
                    :style="{
                        background: values[f.key] ? '#2A2110' : '#111A2B',
                        border: `1px solid ${values[f.key] ? '#F4B740' : '#2C3D5C'}`,
                        color: values[f.key] ? '#F4B740' : '#93A2BA',
                    }"
                    @click="values[f.key] = values[f.key] ? 0 : 1"
                >
                    {{ values[f.key] ? 'ON' : 'OFF' }}
                </button>
                <span v-else class="flex items-center gap-2">
                    <input
                        v-model.number="values[f.key]"
                        type="number"
                        :min="f.min"
                        :max="f.max"
                        class="h-10 w-[84px] rounded-lg border bg-[#0b1220] px-2 text-right text-[16px] text-[#e8eef5]"
                        :class="
                            errors[f.key]
                                ? 'border-[#e5484d]'
                                : 'border-[#2c3d5c]'
                        "
                    />
                    <span
                        v-if="f.kind === 'seconds'"
                        class="mono w-4 text-[12px] text-[#93a2ba]"
                        >s</span
                    >
                </span>
            </label>
        </section>

        <div
            class="sticky bottom-0 flex flex-wrap gap-3 bg-[#0b1220]/95 py-3 backdrop-blur"
        >
            <button
                type="button"
                class="btn btn-primary flex-1"
                :disabled="saving"
                @click="save"
            >
                Save defaults
            </button>
            <button
                v-if="!confirmReset"
                type="button"
                class="btn btn-ghost flex-1"
                @click="confirmReset = true"
            >
                Reset to shipped defaults
            </button>
            <button
                v-else
                type="button"
                class="btn btn-danger flex-1"
                @click="reset"
            >
                Yes, reset every rule
            </button>
        </div>
    </AdminFrame>
</template>
