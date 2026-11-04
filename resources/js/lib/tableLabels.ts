/**
 * Phone card lists for plain tables (docs/DEVICE-CERTIFICATION.md). On phones every `main table.table-auto` is shown
 * as a list of cards (app.css); this copies each column header into `data-label` on its cells so each card row says
 * what the value is. Cells that already carry data-label (DataTable) are left alone. Runs once per DOM change batch.
 */
function labelTable(table: HTMLTableElement) {
    const head = table.tHead?.rows[table.tHead.rows.length - 1];
    if (!head) return;
    const labels: string[] = [];
    for (const th of Array.from(head.cells)) {
        const text = (th.textContent ?? '').replace(/\s+/g, ' ').trim();
        for (let i = 0; i < (th.colSpan || 1); i++) labels.push(text);
    }
    for (const body of Array.from(table.tBodies)) {
        for (const row of Array.from(body.rows)) {
            let col = 0;
            for (const cell of Array.from(row.cells)) {
                if (!cell.hasAttribute('data-label')) cell.setAttribute('data-label', cell.colSpan > 1 ? '' : (labels[col] ?? ''));
                col += cell.colSpan || 1;
            }
        }
    }
}

let queued = false;
function run() {
    queued = false;
    if (document.documentElement.dataset.device !== 'phone') return;
    document.querySelectorAll<HTMLTableElement>('main table.table-auto:not([data-keep-table])').forEach(labelTable);
}
const schedule = () => { if (!queued) { queued = true; requestAnimationFrame(run); } };

export function installTableLabels() {
    if (typeof window === 'undefined' || typeof MutationObserver === 'undefined') return;
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    window.addEventListener('opes:device', schedule);
    schedule();
}
