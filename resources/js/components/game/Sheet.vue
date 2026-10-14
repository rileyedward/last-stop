<script setup lang="ts">
import { onMounted, ref } from 'vue';

// Teleports only after mounting, so server rendering and hydration agree.
const mounted = ref(false);
onMounted(() => (mounted.value = true));

defineProps<{ open: boolean; title?: string }>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <Teleport v-if="mounted" to="body">
        <Transition name="ls-fade">
            <div v-if="open" class="sheet-backdrop" @click="emit('close')" />
        </Transition>
        <Transition name="ls-fade">
            <section
                v-if="open"
                class="sheet ls-root"
                role="dialog"
                :aria-label="title"
                style="min-height: 0"
            >
                <div class="sheet-grip" />
                <div v-if="title" class="flex items-center justify-between">
                    <h2 class="disp m-0 text-[32px] leading-[.9] font-black">
                        {{ title }}
                    </h2>
                    <button
                        type="button"
                        class="flex h-11 w-11 items-center justify-center rounded-[10px] bg-[#16213a] text-[#b7c3d6]"
                        aria-label="Close"
                        @click="emit('close')"
                    >
                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.4"
                            stroke-linecap="round"
                        >
                            <path d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>
                <slot />
            </section>
        </Transition>
    </Teleport>
</template>
