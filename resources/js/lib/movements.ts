/** The stock movements page showing just these movements (a report flag's evidence). */
export const movementsHref = (ids: number[]) => (ids.length ? route('stock.movements', { ids: ids.slice(0, 500).join(',') }) : null);
