export type DayGroup<T> = { key: string; label: string; overdue: boolean; items: T[] };

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Dates here are Y-m-d in Brisbane, decided on the server. UTC keeps the viewer's time zone out of it.
const utc = (date: string) => new Date(`${date}T00:00:00Z`);

export const dayLabel = (date: string) => {
    const d = utc(date);
    return `${DAYS[d.getUTCDay()]} ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`;
};

const tomorrowOf = (today: string) => new Date(utc(today).getTime() + 86_400_000).toISOString().slice(0, 10);

/**
 * OVERDUE first, then Today, Tomorrow and each later day, then anything with no date.
 * Keeps the given order within a day. `late` decides if a past date counts as overdue:
 * a dispatched order from last week isn't, and gets its date as a heading.
 */
export function dayGroups<T>(items: T[], dateOf: (item: T) => string | null, today: string, late: (item: T) => boolean = () => true): DayGroup<T>[] {
    const tomorrow = tomorrowOf(today);
    const groups = new Map<string, DayGroup<T>>();

    for (const item of items) {
        const date = dateOf(item);
        const overdue = date !== null && date < today && late(item);
        const key = overdue ? '0' : (date ?? '9');
        const label = overdue ? 'OVERDUE' : date === null ? 'No delivery date' : date === today ? 'Today' : date === tomorrow ? 'Tomorrow' : dayLabel(date);
        if (!groups.has(key)) groups.set(key, { key, label, overdue, items: [] });
        groups.get(key)!.items.push(item);
    }

    return [...groups.values()].sort((a, b) => a.key.localeCompare(b.key));
}
