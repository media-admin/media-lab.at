/**
 * Impressum Tables – gleichbreite erste Spalten
 *
 * scrollWidth misst die tatsächliche Inhaltsbreite unabhängig
 * vom Container. Double rAF stellt sicher, dass das Layout
 * vollständig berechnet ist bevor gemessen wird.
 */
export function initImpressumTables() {
    if (!document.body.classList.contains('impressum-page')) return;

    const firstCells = [...document.querySelectorAll('.wp-block-table td:first-child')];
    if (!firstCells.length) return;

    // Explizite Breiten zurücksetzen
    firstCells.forEach(cell => {
        cell.style.width = '';
        cell.style.minWidth = '';
    });

    // Double rAF: erster Frame = Layout neu berechnen, zweiter = messen
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            let maxWidth = 0;
            firstCells.forEach(cell => {
                maxWidth = Math.max(maxWidth, cell.scrollWidth);
            });

            if (maxWidth > 0) {
                firstCells.forEach(cell => {
                    cell.style.minWidth = maxWidth + 'px';
                    cell.style.width    = maxWidth + 'px';
                });
            }
        });
    });
}
