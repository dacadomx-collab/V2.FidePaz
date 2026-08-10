// Componente de tabla de datos reutilizable (2026-08-08) -- resuelve de una
// vez la deuda de UX pedida repetidamente: un solo cuadro de búsqueda (sin
// ícono de basura -- "Buscar" resetea el estado previo automáticamente) y
// orden ascendente/descendente por click en el encabezado de columna, en
// vez del dropdown "Ordenar por". Sin dependencias de build, un solo
// archivo, vanilla JS.

// Escapa valores antes de interpolarlos en innerHTML -- las columnas sin
// `render` propio (y cualquier página que la use para texto plano) insertan
// datos que vienen de la base (nombres, calles, etc.), no HTML de confianza.
export function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * @param {HTMLElement} container - elemento donde se monta la tabla completa.
 * @param {Object} options
 * @param {Array<{key:string,label:string,sortable?:boolean,render?:Function}>} options.columns
 * @param {(state:{search:string,sortKey:string,sortDir:string,page:number}) => Promise<{items:Array,meta:{total:number,totalPages:number,page:number}}>} options.fetchPage
 * @param {(item:Object) => string} [options.renderActions] - HTML de los botones de acción por fila.
 * @param {string} [options.searchPlaceholder]
 * @param {string} [options.emptyMessage]
 */
export function createDataTable(container, options) {
    const state = {
        search: '',
        sortKey: options.defaultSortKey || null,
        sortDir: options.defaultSortDir || 'asc',
        page: 1,
    };

    container.innerHTML = `
        <div class="panel-search">
            <input type="search" placeholder="${options.searchPlaceholder || 'Buscar…'}" aria-label="Buscar">
            <button type="button" class="btn">Buscar</button>
        </div>
        <div class="panel-table-wrap">
            <table class="panel-table">
                <thead><tr></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="panel-pagination">
            <span class="pagination-summary"></span>
            <div class="pages"></div>
        </div>
    `;

    const searchInput = container.querySelector('input[type="search"]');
    const searchBtn = container.querySelector('.panel-search .btn');
    const theadRow = container.querySelector('thead tr');
    const tbody = container.querySelector('tbody');
    const summaryEl = container.querySelector('.pagination-summary');
    const pagesEl = container.querySelector('.pages');

    theadRow.innerHTML = options.columns.map((col) => {
        if (!col.sortable) { return `<th>${col.label}</th>`; }
        return `<th data-sortable data-key="${col.key}">${col.label} <span class="sort-arrow">↕</span></th>`;
    }).join('') + (options.renderActions ? '<th>Acciones</th>' : '');

    theadRow.querySelectorAll('th[data-sortable]').forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.dataset.key;
            if (state.sortKey === key) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortKey = key;
                state.sortDir = 'asc';
            }
            state.page = 1;
            load();
        });
    });

    function updateSortIndicators() {
        theadRow.querySelectorAll('th[data-sortable]').forEach((th) => {
            const isActive = th.dataset.key === state.sortKey;
            th.classList.toggle('sort-active', isActive);
            th.querySelector('.sort-arrow').textContent = isActive ? (state.sortDir === 'asc' ? '↑' : '↓') : '↕';
        });
    }

    // "Buscar" resetea cualquier búsqueda/orden/página previa -- sin botón
    // de limpiar aparte, tal como se pidió explícitamente.
    function runSearch() {
        state.search = searchInput.value.trim();
        state.sortKey = options.defaultSortKey || null;
        state.sortDir = options.defaultSortDir || 'asc';
        state.page = 1;
        load();
    }

    searchBtn.addEventListener('click', runSearch);
    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { runSearch(); }
    });

    function renderRows(items) {
        if (items.length === 0) {
            const colspan = options.columns.length + (options.renderActions ? 1 : 0);
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="panel-empty-state">${options.emptyMessage || 'Sin resultados.'}</td></tr>`;
            return;
        }
        tbody.innerHTML = items.map((item) => {
            const cells = options.columns.map((col) => {
                const value = col.render ? col.render(item) : escapeHtml(item[col.key]);
                return `<td>${value}</td>`;
            }).join('');
            const actions = options.renderActions ? `<td class="row-actions">${options.renderActions(item)}</td>` : '';
            return `<tr data-id="${item.id}">${cells}${actions}</tr>`;
        }).join('');
    }

    function renderPagination(meta) {
        summaryEl.textContent = `${meta.total} resultado${meta.total === 1 ? '' : 's'} — página ${meta.page} de ${meta.totalPages || 1}`;

        const totalPages = meta.totalPages || 1;
        const current = meta.page;
        const pages = [];
        const windowSize = 2;
        for (let p = 1; p <= totalPages; p++) {
            if (p === 1 || p === totalPages || Math.abs(p - current) <= windowSize) {
                pages.push(p);
            } else if (pages[pages.length - 1] !== '…') {
                pages.push('…');
            }
        }

        pagesEl.innerHTML = pages.map((p) => {
            if (p === '…') { return `<span>…</span>`; }
            return `<button type="button" data-page="${p}" class="${p === current ? 'active' : ''}">${p}</button>`;
        }).join('');

        pagesEl.querySelectorAll('button[data-page]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.page = Number(btn.dataset.page);
                load();
            });
        });
    }

    async function load() {
        updateSortIndicators();
        tbody.innerHTML = `<tr><td colspan="${options.columns.length + 1}" class="panel-empty-state">Cargando…</td></tr>`;
        try {
            const { items, meta } = await options.fetchPage({ ...state });
            renderRows(items);
            renderPagination(meta);
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="${options.columns.length + 1}" class="panel-empty-state">${err.message || 'Error al cargar.'}</td></tr>`;
        }
    }

    load();

    return { reload: load, getState: () => ({ ...state }) };
}
