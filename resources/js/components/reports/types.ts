export interface InsightFilters {
    from: string;
    to: string;
    site_id: number | null;
    range_id: number | null;
    supplier_id: number | null;
    product_id: number | null;
}
export interface InsightOptions {
    sites: { id: number; name: string }[];
    ranges: { id: number; name: string }[];
    suppliers: { id: number; name: string }[];
    product: { id: number; sku: string; name: string } | null;
}
export type ColumnKind = 'text' | 'money' | 'int' | 'pct' | 'cost' | 'date';
export type Row = Record<string, any>;
export interface Column {
    key: string;
    label: string;
    kind?: ColumnKind;
    /** A link for the cell, if any. */
    href?: (row: Row) => string | null;
    /** Text under the value, smaller. */
    sub?: (row: Row) => string | null;
    /** Extra classes for the cell, e.g. a flag colour. */
    cellClass?: (row: Row) => string;
}
