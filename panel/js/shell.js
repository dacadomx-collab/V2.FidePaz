// Shell compartido del panel nuevo (2026-08-08): sidebar de navegación,
// topbar con usuario activo, botón día/noche y botón subir arriba. Se
// invoca una vez por página con el ítem de navegación activo -- sin build,
// sin templating, solo un string armado en JS (igual de simple que
// reportes/*.html, consistente con "sin build" del plan de migración).
import { getActiveUser, clearSession, goToLogin, panelRoot } from './api.js';

const THEME_KEY = 'fidepaz_admin_theme';

// "avisos.html" (renombrado "Comunicados" en el menú, 2026-08-12) NO está en
// ADMIN_NAV_ITEMS a propósito: para admin/super_admin ya existe
// "comunicados.html" (CRUD real, ve también borradores/privados) e
// "informes.html" -- agregar además el feed de solo lectura duplicaría la
// etiqueta "Comunicados" dos veces en el mismo sidebar (una para gestionar,
// otra para ver) sin aportar nada que el CRUD no muestre ya. Sigue siendo
// accesible por URL directa para cualquier rol autenticado
// (Auth::requireUser() nada más en handle_avisos_feed), simplemente no se
// linkea aquí para admins.
const ADMIN_NAV_ITEMS = [
    { id: 'inicio', label: 'Inicio', href: 'index.html', icon: '🏠' },
    { id: 'propietarios', label: 'Propietarios', href: 'propietarios.html', icon: '👤' },
    { id: 'propiedades', label: 'Propiedades', href: 'propiedades.html', icon: '🏠' },
    { id: 'cuotas', label: 'Cuotas', href: 'cuotas.html', icon: '💳' },
    { id: 'pagos', label: 'Pagos', href: 'pagos.html', icon: '🧾' },
    { id: 'reportes', label: 'Estado de Cuenta', href: 'reportes.html', icon: '📊' },
    { id: 'comunicados', label: 'Comunicados', href: 'comunicados.html', icon: '📣' },
    { id: 'informes', label: 'Informes Financieros', href: 'informes.html', icon: '📑' },
    { id: 'mensajes', label: 'Mensajes', href: 'mensajes.html', icon: '💬' },
    { id: 'caja', label: 'Caja', href: 'caja.html', icon: '💵' },
    { id: 'manual', label: 'Manual del Sistema', href: 'manual.html', icon: '📖' },
];

// Los colonos (role="owner") no tienen permiso en las rutas de gestión de
// arriba (Auth::requireRole las restringe a admin/super_admin en el
// backend) -- mostrarles esos enlaces solo llevaría a un 403. "avisos.html"
// SÍ es de lectura para cualquier rol autenticado (Auth::requireUser() nada
// más, ver handle_avisos_feed) -- agregado 2026-08-12 como página propia en
// vez de vivir amontonada dentro de "Mi cuenta", a pedido explícito del
// Arquitecto por limpieza visual. Etiqueta acortada a "Comunicados" el mismo
// día (sin colisión aquí porque el colono no tiene otra entrada llamada
// igual, a diferencia del admin -- ver nota arriba).
const OWNER_NAV_ITEMS = [
    { id: 'mi-cuenta', label: 'Mi cuenta', href: 'mi-cuenta.html', icon: '🏡' },
    { id: 'avisos', label: 'Comunicados', href: 'avisos.html', icon: '🔔' },
    { id: 'mensajes', label: 'Mensajes', href: 'mensajes.html', icon: '💬' },
    { id: 'manual', label: 'Manual del Sistema', href: 'manual.html', icon: '📖' },
];

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    const btn = document.getElementById('panel-theme-toggle');
    if (btn) { btn.textContent = theme === 'dark' ? '☀️' : '🌙'; }
}

function initTheme() {
    const saved = localStorage.getItem(THEME_KEY);
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(saved || (prefersDark ? 'dark' : 'light'));
}

function buildNav(activeId, role) {
    let items = role === 'owner' ? OWNER_NAV_ITEMS : ADMIN_NAV_ITEMS;
    // RBAC (2026-08-12/13): "Pagos" y "Caja" quedan reservados a
    // super_admin -- role=admin ve el resto del panel operativo normal,
    // pero esos ítems se ocultan del sidebar. Esto es solo UX (no mostrar
    // un link que llevaría a un 403); el límite real de seguridad son los
    // Auth::requireRole(['super_admin']) agregados en los propios
    // endpoints (/payment/* en quotas.php, /caja/* en caja.php) -- ocultar
    // el link nunca sustituye ese control del lado del servidor.
    if (role === 'admin') {
        items = items.filter((item) => item.id !== 'pagos' && item.id !== 'caja');
    }
    return items.map((item) => {
        const activeClass = item.id === activeId ? ' active' : '';
        return `<li><a class="${activeClass.trim()}" href="${item.href}">${item.icon} ${item.label}</a></li>`;
    }).join('');
}

export function renderShell(activeId, pageTitle) {
    const user = getActiveUser();
    const root = panelRoot();

    const shellHtml = `
        <aside class="panel-sidebar">
            <div class="brand">
                <img src="${root}../assets/img/fidepaz-logo.png" alt="FidePaz" onerror="this.style.display='none'">
            </div>
            <ul class="panel-nav">${buildNav(activeId, user ? user.role : null)}</ul>
        </aside>
        <main class="panel-main">
            <div class="panel-topbar">
                <h1>${pageTitle}</h1>
                <div class="panel-user-chip">
                    <span>${user ? user.name : ''}</span>
                    <button type="button" class="panel-theme-btn" id="panel-theme-toggle" aria-label="Cambiar modo día/noche"></button>
                    <button type="button" class="btn btn-outline" id="panel-logout-btn">Cerrar sesión</button>
                </div>
            </div>
            <div id="panel-content"></div>
        </main>
    `;

    const shellRoot = document.getElementById('panel-shell-root');
    shellRoot.className = 'panel-shell';
    shellRoot.innerHTML = shellHtml;

    document.getElementById('panel-logout-btn').addEventListener('click', () => {
        clearSession();
        goToLogin();
    });

    document.getElementById('panel-theme-toggle').addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    });

    buildScrollTopButton();
    initTheme();

    return document.getElementById('panel-content');
}

// Botón "subir arriba" (2026-08-10): único flotante que queda -- el
// conmutador de tema se movió al navbar superior (ver renderShell) porque,
// como overlay fijo en la esquina superior derecha de todo el viewport, le
// caía encima al botón "Cerrar sesión" del topbar (reporte de campo
// 2026-08-10). El scroll-top sigue como overlay porque no compite con
// ningún control del navbar -- solo aparece tras bajar 300px.
function buildScrollTopButton() {
    if (document.getElementById('panel-scroll-top')) { return; }

    const topBtn = document.createElement('button');
    topBtn.id = 'panel-scroll-top';
    topBtn.type = 'button';
    topBtn.className = 'fp-float-btn fp-scroll-top-btn';
    topBtn.textContent = '↑';
    topBtn.setAttribute('aria-label', 'Subir al inicio');
    topBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

    document.body.appendChild(topBtn);

    window.addEventListener('scroll', () => {
        topBtn.classList.toggle('fp-visible', window.scrollY > 300);
    });
}
