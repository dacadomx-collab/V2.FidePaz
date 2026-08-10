// Drawer de historial de auditoría (2026-08-10) -- consume el nuevo
// GET /audit-logs (navegador global filtrable), distinto de
// Audit::history()/GET /{entidad}/{id}/history (que ya existía desde el
// 2026-08-07 para el historial de UNA fila específica). Reutilizable
// desde cualquier pantalla del panel: cada una lo abre con su propio
// entityType.
import { api } from '../api.js';
import { escapeHtml } from './dataTable.js';

const ACTION_LABEL = { create: 'Creó', update: 'Actualizó', delete: 'Eliminó' };

function fmtDate(iso) {
    if (!iso) { return ''; }
    return iso.replace('T', ' ').slice(0, 19);
}

function renderDetails(details) {
    if (!details || typeof details !== 'object') { return ''; }
    return escapeHtml(JSON.stringify(details));
}

export function openAuditDrawer(entityType, entityLabel) {
    const root = document.createElement('div');
    root.className = 'panel-drawer-overlay';
    root.innerHTML = `
        <div class="panel-drawer">
            <div class="panel-drawer-header">
                <h2>📜 Historial — ${escapeHtml(entityLabel)}</h2>
                <button type="button" class="panel-drawer-close" aria-label="Cerrar">✕</button>
            </div>
            <div class="panel-drawer-filters">
                <div class="panel-form-field">
                    <label for="audit-from">Desde</label>
                    <input type="date" id="audit-from">
                </div>
                <div class="panel-form-field">
                    <label for="audit-to">Hasta</label>
                    <input type="date" id="audit-to">
                </div>
                <button type="button" class="btn" id="audit-filter-btn">Filtrar</button>
            </div>
            <div class="panel-drawer-body" id="audit-drawer-body">
                <p class="panel-empty-state">Cargando…</p>
            </div>
            <div class="panel-drawer-pagination" id="audit-drawer-pagination"></div>
        </div>
    `;
    document.body.appendChild(root);

    const state = { page: 1 };
    const bodyEl = root.querySelector('#audit-drawer-body');
    const pagerEl = root.querySelector('#audit-drawer-pagination');

    function close() {
        root.remove();
    }

    root.addEventListener('click', (event) => {
        if (event.target === root) { close(); }
    });
    root.querySelector('.panel-drawer-close').addEventListener('click', close);
    root.querySelector('#audit-filter-btn').addEventListener('click', () => {
        state.page = 1;
        load();
    });

    async function load() {
        bodyEl.innerHTML = '<p class="panel-empty-state">Cargando…</p>';
        try {
            const from = root.querySelector('#audit-from').value;
            const to = root.querySelector('#audit-to').value;
            const data = await api.get('/audit-logs', { entityType, from, to, page: state.page });

            if (data.items.length === 0) {
                bodyEl.innerHTML = '<p class="panel-empty-state">Sin actividad registrada en este rango.</p>';
                pagerEl.innerHTML = '';
                return;
            }

            bodyEl.innerHTML = data.items.map((log) => `
                <div class="panel-audit-entry">
                    <div class="panel-audit-entry-head">
                        <span class="panel-badge">${ACTION_LABEL[log.action] || log.action}</span>
                        <span class="panel-note">#${log.entityId}</span>
                    </div>
                    <p class="panel-note">${escapeHtml(log.changedByName) || 'Sistema'} — ${fmtDate(log.createdAt)}</p>
                    <details>
                        <summary class="panel-note">Ver detalle</summary>
                        <pre class="panel-audit-json">${renderDetails(log.details)}</pre>
                    </details>
                </div>
            `).join('');

            const totalPages = data.meta.totalPages || 1;
            pagerEl.innerHTML = `
                <button type="button" id="audit-prev" ${state.page <= 1 ? 'disabled' : ''}>← Anterior</button>
                <span class="panel-note">Página ${state.page} de ${totalPages}</span>
                <button type="button" id="audit-next" ${state.page >= totalPages ? 'disabled' : ''}>Siguiente →</button>
            `;
            const prevBtn = pagerEl.querySelector('#audit-prev');
            const nextBtn = pagerEl.querySelector('#audit-next');
            if (prevBtn) { prevBtn.addEventListener('click', () => { state.page -= 1; load(); }); }
            if (nextBtn) { nextBtn.addEventListener('click', () => { state.page += 1; load(); }); }
        } catch (err) {
            bodyEl.innerHTML = `<p class="panel-empty-state">${escapeHtml(err.message || 'Error al cargar el historial.')}</p>`;
            pagerEl.innerHTML = '';
        }
    }

    load();
}
