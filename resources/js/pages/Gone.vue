<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Flaps from '@/components/game/Flaps.vue';

defineProps<{ code: string }>();
</script>

<template>
    <Head title="No train here" />
    <div class="ls-root">
        <div
            class="ls-phone items-center gap-[22px] px-5 pt-20 pb-7 text-center"
        >
            <Flaps :text="code" size="md" />
            <div class="label text-[#ff8e92]">No train at this code</div>
            <h1 class="disp m-0 text-[50px] leading-[.88] font-black">
                Platform<br />empty
            </h1>
            <div
                class="max-w-[300px] text-[16px] leading-[1.45] text-[#b7c3d6]"
            >
                This lobby doesn't exist or it closed after sitting empty for a
                while. Ask for a new invite link, or start your own.
            </div>
            <div class="flex-1" />
            <div class="flex w-full gap-[10px]">
                <Link href="/join" class="btn btn-ghost flex-1 text-[17px]"
                    >Another code</Link
                >
                <Link href="/" class="btn btn-primary flex-1 text-[17px]"
                    >Home</Link
                >
            </div>
        </div>
    </div>
</template>
