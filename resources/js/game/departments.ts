import type { DeptKey } from './types';

export type DeptMeta = {
    name: string;
    short: string;
    code: string;
    color: string;
    ink: string;
    on: string;
    icon: string;
};

/** Colour plus a glyph, never colour alone (art direction). */
export const DEPARTMENTS: Record<DeptKey, DeptMeta> = {
    engineering: {
        name: 'Engineering',
        short: 'Engineering',
        code: 'ENG',
        color: '#EF7B45',
        ink: '#EF7B45',
        on: '#1A0D06',
        icon: '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7.5"/><path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1"/>',
    },
    operations: {
        name: 'Operations',
        short: 'Operations',
        code: 'OPS',
        color: '#5CC8A0',
        ink: '#2E9C74',
        on: '#06180F',
        icon: '<rect x="7.5" y="1.5" width="9" height="14" rx="4.5"/><circle cx="12" cy="5.8" r="1.6"/><circle cx="12" cy="11.2" r="1.6"/><path d="M12 15.5v6M8 21.5h8"/>',
    },
    passenger_services: {
        name: 'Passenger Services',
        short: 'Passenger Svcs',
        code: 'PAS',
        color: '#C39BEB',
        ink: '#9161C9',
        on: '#1A0E28',
        icon: '<path d="M4 9h12v4a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M16 10.5h1.5a2 2 0 0 1 0 4H16"/><path d="M3 21h15"/><path d="M8 2.5c0 1.5 1 1.5 1 3.5M12 2.5c0 1.5 1 1.5 1 3.5"/>',
    },
    security: {
        name: 'Security',
        short: 'Security',
        code: 'SEC',
        color: '#5AA9E6',
        ink: '#2F7FBF',
        on: '#06121F',
        icon: '<path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z"/><circle cx="12" cy="10" r="2"/><path d="M12 12v4"/>',
    },
};

export function dept(key: string | null | undefined): DeptMeta {
    return (
        DEPARTMENTS[(key ?? 'engineering') as DeptKey] ??
        DEPARTMENTS.engineering
    );
}

export function signed(n: number): string {
    return n > 0 ? `+${n}` : n < 0 ? `−${Math.abs(n)}` : '0';
}

/** A card's effects as short labels, like "ENG −2". */
export function effectList(
    effects: Partial<Record<string, number>>,
    only: string[] | null = null,
): { label: string; value: number; key: string }[] {
    const out: { label: string; value: number; key: string }[] = [];
    for (const [key, value] of Object.entries(effects)) {
        if (!value || (only && !only.includes(key))) {
            continue;
        }
        out.push({ key, value, label: `${dept(key).code} ${signed(value)}` });
    }

    return out;
}

export function initial(name: string): string {
    return (name.trim()[0] ?? '?').toUpperCase();
}
