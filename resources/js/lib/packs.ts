/**
 * Quantities are typed as packs plus loose base units and saved as base units
 * on the server (App\Support\PackQty). Same wording as there.
 */
export type Pack = {
    per: number;
    name: string;
    base_unit: string;
};

// Type aliases, not interfaces: Inertia forms need them assignable to an index signature.
export type PackEntry = {
    packs: number | null;
    each: number | null;
};

const INVARIANT = ['each', 'ea', 'kg', 'g', 'l', 'ml', 'ltr', 'm'];

export const unitLabel = (unit: string, n: number) =>
    n === 1 || INVARIANT.includes(unit.toLowerCase()) ? unit : unit.endsWith('s') ? unit : `${unit}s`;

export const toBase = (e: PackEntry, per: number) => (e.packs ?? 0) * Math.max(1, per) + (e.each ?? 0);

export const isBlank = (e: PackEntry) => (e.packs === null || e.packs === undefined) && (e.each === null || e.each === undefined);

/** Base units back into the boxes: whole packs, the rest loose. */
export const fromBase = (base: number | null, per: number): PackEntry =>
    base === null ? { packs: null, each: null } : per > 1 ? { packs: Math.floor(base / per), each: base % per || null } : { packs: null, each: base };

/** "1 Case", "1 Case + 5 each", "5 units". */
export const packLabel = (base: number, pack: Pack) => {
    if (pack.per <= 1) return `${base} ${unitLabel(pack.base_unit, base)}`;
    const abs = Math.abs(base);
    const packs = Math.floor(abs / pack.per);
    const loose = abs % pack.per;
    const text =
        loose === 0
            ? `${packs} ${pack.name}`
            : packs === 0
              ? `${loose} ${unitLabel(pack.base_unit, loose)}`
              : `${packs} ${pack.name} + ${loose} ${unitLabel(pack.base_unit, loose)}`;

    return base < 0 ? `−${text}` : text;
};

export const packHint = (pack: Pack) => (pack.per > 1 ? `1 ${pack.name} = ${pack.per} ${unitLabel(pack.base_unit, pack.per)}` : null);
