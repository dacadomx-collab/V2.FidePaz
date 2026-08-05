// assets/js/main.js — FidePaz V2.0 - Web Pública
//
// Nota: la plantilla original hacía fetch('api/status_check.php'), un endpoint
// PHP que pertenecía al scaffold genérico ya eliminado. Este sitio público es
// estático y no depende de ningún backend propio; la única API real del
// proyecto es Go en /api/v2/ (consumida por /administrator, no por esta web).

document.addEventListener('DOMContentLoaded', () => {
    // Año dinámico en el footer.
    const yearEl = document.getElementById('current-year');
    if (yearEl) {
        yearEl.textContent = new Date().getFullYear();
    }

    // Menú móvil (ARF-Grid es mobile-first; el nav colapsa por defecto en pantallas angostas).
    const toggle = document.getElementById('nav-toggle');
    const nav = document.getElementById('site-nav');
    if (toggle && nav) {
        toggle.addEventListener('click', () => {
            const isOpen = nav.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(isOpen));
        });
    }

    initContactForm();
    initThemeToggle();
    initScrollTop();
});

// Conmutador Día/Noche: persiste la elección explícita del visitante en
// localStorage (data-theme en <html>), sin depender solo de
// prefers-color-scheme del sistema operativo.
function initThemeToggle() {
    const btn = document.getElementById('theme-toggle');
    if (!btn) {
        return;
    }

    const root = document.documentElement;
    const STORAGE_KEY = 'fidepaz-theme';
    const systemPrefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

    const applyTheme = (theme) => {
        root.setAttribute('data-theme', theme);
        btn.setAttribute('aria-pressed', String(theme === 'dark'));
        btn.textContent = theme === 'dark' ? '☀️' : '🌙';
        btn.setAttribute('aria-label', theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
    };

    const stored = localStorage.getItem(STORAGE_KEY);
    applyTheme(stored || (systemPrefersDark() ? 'dark' : 'light'));

    btn.addEventListener('click', () => {
        const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem(STORAGE_KEY, next);
        applyTheme(next);
    });
}

// Botón flotante "ir arriba": aparece tras 300px de scroll, desplazamiento suave.
function initScrollTop() {
    const btn = document.getElementById('scroll-top-btn');
    if (!btn) {
        return;
    }

    const toggleVisibility = () => {
        btn.classList.toggle('is-visible', window.scrollY > 300);
    };

    window.addEventListener('scroll', toggleVisibility, { passive: true });
    toggleVisibility();

    btn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

// Elimina cualquier marcado HTML del input antes de usarlo (anti-XSS defensa en
// profundidad; el backend, si llega a existir, debe sanitizar/escapar de nuevo).
function stripHtml(value) {
    return value.replace(/<[^>]*>/g, '').trim();
}

function initContactForm() {
    const form = document.getElementById('contact-form');
    const statusEl = document.getElementById('contact-status');
    if (!form || !statusEl) {
        return;
    }

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    const setStatus = (state, message) => {
        statusEl.textContent = message;
        statusEl.setAttribute('data-state', state);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const name = stripHtml(form.name.value);
        const email = stripHtml(form.email.value);
        const message = stripHtml(form.message.value);

        if (!name || !email || !message) {
            setStatus('error', 'Completa todos los campos antes de enviar.');
            return;
        }
        if (!emailPattern.test(email)) {
            setStatus('error', 'Ingresa un correo electrónico válido.');
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        setStatus('', 'Enviando…');

        try {
            const response = await fetch('/api/v2/contact', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, email, message }),
            });

            if (!response.ok) {
                throw new Error('request-failed');
            }

            form.reset();
            setStatus('success', 'Mensaje enviado. Gracias por contactarnos.');
        } catch (err) {
            setStatus('error', 'No fue posible enviar el mensaje. Contacta a la administración por los canales oficiales de la asociación.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
            }
        }
    });
}
