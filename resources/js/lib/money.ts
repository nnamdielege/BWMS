/** $1,234.56; a dash for nothing. */
export const money = (n: number | null | undefined) =>
    n === null || n === undefined
        ? '—'
        : `${n < 0 ? '−' : ''}$${Math.abs(n).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const num = (n: number | null | undefined) => (n === null || n === undefined ? '—' : n.toLocaleString('en-AU'));

export const pct = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `${n}%`);
