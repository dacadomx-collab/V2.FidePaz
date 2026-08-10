// Shell compartido del panel nuevo (2026-08-08): sidebar de navegación,
// topbar con usuario activo, botón día/noche y botón subir arriba. Se
// invoca una vez por página con el ítem de navegación activo -- sin build,
// sin templating, solo un string armado en JS (igual de simple que
// reportes/*.html, consistente con "sin build" del plan de migración).
import { getActiveUser, clearSession, goToLogin, panelRoot } from './api.js';

const THEME_KEY = 'fidepaz_admin_theme';

const NAV_ITEMS = [
    { id: 'propietarios', label: 'Propietarios', href: 'propietarios.html', icon: '👤' },
    { id: 'propiedades', label: 'Propiedades', href: 'propiedades.html', icon: '🏠' },
    { id: 'cuotas', label: 'Cuotas', href: 'cuotas.html', icon: '💳' },
    { id: 'pagos', label: 'Pagos', href: 'pagos.html', icon: '🧾' },
    { id: 'reportes', label: 'Reportes', href: 'reportes.html', icon: '📊' },
    { id: 'comunicados', label: 'Comunicados', href: 'comunicados.html', icon: '📣' },
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

function buildNav(activeId) {
    return NAV_ITEMS.map((item) => {
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
                <strong>FidePaz</strong>
            </div>
            <ul class="panel-nav">${buildNav(activeId)}</ul>
        </aside>
        <main class="panel-main">
            <div class="panel-topbar">
                <h1>${pageTitle}</h1>
                <div class="panel-user-chip">
                    <span>${user ? user.name : ''}</span>
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

    buildFloatingButtons();
    initTheme();

    return document.getElementById('panel-content');
}

function buildFloatingButtons() {
    if (document.getElementById('panel-theme-toggle')) { return; }

    const themeBtn = document.createElement('button');
    themeBtn.id = 'panel-theme-toggle';
    themeBtn.type = 'button';
    themeBtn.className = 'fp-float-btn fp-theme-btn';
    themeBtn.setAttribute('aria-label', 'Cambiar modo día/noche');
    themeBtn.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    });

    const topBtn = document.createElement('button');
    topBtn.id = 'panel-scroll-top';
    topBtn.type = 'button';
    topBtn.className = 'fp-float-btn fp-scroll-top-btn';
    topBtn.textContent = '↑';
    topBtn.setAttribute('aria-label', 'Subir al inicio');
    topBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

    document.body.appendChild(themeBtn);
    document.body.appendChild(topBtn);

    window.addEventListener('scroll', () => {
        topBtn.classList.toggle('fp-visible', window.scrollY > 300);
    });
}
