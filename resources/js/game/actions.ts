import { router } from '@inertiajs/vue3';

/** Post to a board endpoint and come back to the same page. */
export function post(
    url: string,
    data: Record<string, string | number | boolean | null> = {},
    onDone?: () => void,
): void {
    router.post(url, data, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => onDone?.(),
    });
}

export function boardActions(code: string) {
    const base = `/board/${code}`;

    return {
        act: (
            action: string,
            data: Record<string, string | boolean> = {},
            onDone?: () => void,
        ) => post(`${base}/act`, { action, ...data }, onDone),
        lobby: (
            path: string,
            data: Record<string, string | boolean> = {},
            onDone?: () => void,
        ) => post(`${base}/${path}`, data, onDone),
    };
}
