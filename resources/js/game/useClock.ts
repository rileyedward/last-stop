import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * A countdown drawn from the server's deadline and the server's clock, so
 * every screen shows the same time whatever the phone's clock says.
 */
export function useClock(
    deadline: Ref<number | null>,
    serverNow: Ref<number>,
    paused: Ref<number | null>,
    onZero?: () => void,
) {
    const offset = ref(serverNow.value - Date.now());
    const now = ref(Date.now() + offset.value);
    let fired = false;

    watch(serverNow, (value) => {
        offset.value = value - Date.now();
    });
    watch(deadline, () => {
        fired = false;
    });

    let timer: number | undefined;
    onMounted(() => {
        offset.value = serverNow.value - Date.now();
        timer = window.setInterval(tick, 250);
    });
    const tick = () => {
        now.value = Date.now() + offset.value;
        if (deadline.value !== null && now.value >= deadline.value && !fired) {
            fired = true;
            // A moment after zero, ask the server to move the game on.
            window.setTimeout(() => onZero?.(), 400);
        }
    };
    onBeforeUnmount(() => window.clearInterval(timer));

    const remainingMs = computed(() => {
        if (paused.value !== null) {
            return paused.value;
        }
        if (deadline.value === null) {
            return null;
        }

        return Math.max(0, deadline.value - now.value);
    });

    const label = computed(() => {
        if (remainingMs.value === null) {
            return '';
        }
        const total = Math.ceil(remainingMs.value / 1000);

        return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
    });

    return { remainingMs, label, now };
}
