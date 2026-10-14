import { router } from '@inertiajs/vue3';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Keep a page current. The table channel only says "something changed";
 * the page then reloads its own view over HTTP. If the socket is down, it
 * polls instead.
 */
export function useLive(
    code: string,
    options: {
        presence?: boolean;
        playerId?: string | null;
        heartbeatSeconds?: number;
    } = {},
) {
    const connected = ref(false);
    const online = ref<Set<string>>(new Set());
    let reloading = false;
    let again = false;
    let poll: number | undefined;
    let beat: number | undefined;

    function refresh(): void {
        if (reloading) {
            again = true;

            return;
        }
        reloading = true;
        router.reload({
            only: ['state'],
            onFinish: () => {
                reloading = false;
                if (again) {
                    again = false;
                    refresh();
                }
            },
        });
    }

    onMounted(() => {
        if (echoIsConfigured()) {
            try {
                const e = echo();
                e.channel(`table.${code}`).listen('.updated', () => refresh());
                if (options.playerId) {
                    e.private(`player.${options.playerId}`).listen(
                        '.updated',
                        () => refresh(),
                    );
                }
                if (options.presence) {
                    e.join(`lobby.${code}`)
                        .here((members: { id: string }[]) => {
                            online.value = new Set(members.map((m) => m.id));
                        })
                        .joining((m: { id: string }) => {
                            online.value = new Set([...online.value, m.id]);
                        })
                        .leaving((m: { id: string }) => {
                            const next = new Set(online.value);
                            next.delete(m.id);
                            online.value = next;
                        });
                }
                const pusher = (
                    e.connector as {
                        pusher?: {
                            connection: {
                                state: string;
                                bind: (ev: string, cb: () => void) => void;
                            };
                        };
                    }
                ).pusher;
                if (pusher) {
                    const sync = () => {
                        connected.value =
                            pusher.connection.state === 'connected';
                    };
                    pusher.connection.bind('state_change', sync);
                    sync();
                }
            } catch {
                connected.value = false;
            }
        }

        // Fall back to polling whenever the socket isn't connected.
        poll = window.setInterval(() => {
            if (!connected.value && document.visibilityState === 'visible') {
                refresh();
            }
        }, 3000);

        if (options.heartbeatSeconds) {
            beat = window.setInterval(() => {
                void fetch(`/board/${code}/heartbeat`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') ?? '',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                });
            }, options.heartbeatSeconds * 1000);
        }
    });

    onBeforeUnmount(() => {
        window.clearInterval(poll);
        window.clearInterval(beat);
        if (echoIsConfigured()) {
            try {
                const e = echo();
                e.leaveChannel(`table.${code}`);
                if (options.playerId) {
                    e.leave(`player.${options.playerId}`);
                }
                if (options.presence) {
                    e.leave(`lobby.${code}`);
                }
            } catch {
                // nothing to clean up
            }
        }
    });

    return { refresh, connected, online };
}
