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

    // Cada módulo se inicializa de forma aislada: si uno falla, no debe
    // impedir que los demás (menú, slider, modal, etc.) sigan funcionando.
    [initContactForm, initThemeToggle, initScrollTop, initHeroSlider, initNewsModal, initSmoothNav, initHeaderShadow, initRevealOnScroll]
        .forEach((init) => {
            try {
                init();
            } catch (err) {
                console.error('[FidePaz] Error inicializando ' + init.name + ':', err);
            }
        });
});

// Sombra sutil en el menú fijo solo cuando ya hay contenido detrás (a partir
// de 8px de scroll) -- evita que se vea "pegada" al fondo cuando está arriba.
function initHeaderShadow() {
    const header = document.getElementById('site-header');
    if (!header) {
        return;
    }
    const update = () => header.classList.toggle('is-scrolled', window.scrollY > 8);
    window.addEventListener('scroll', update, { passive: true });
    update();
}

// Aparición progresiva de secciones al entrar en pantalla (IntersectionObserver
// nativo, sin librerías). Respeta prefers-reduced-motion: si el visitante lo
// pidió, todo se muestra de inmediato sin animar.
function initRevealOnScroll() {
    const targets = document.querySelectorAll('main > section, .card, .news-card, .board-card');
    if (!targets.length) {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        targets.forEach((el) => el.classList.add('is-visible'));
        return;
    }

    if (!('IntersectionObserver' in window)) {
        targets.forEach((el) => el.classList.add('is-visible'));
        return;
    }

    targets.forEach((el) => el.classList.add('reveal-on-scroll'));

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    targets.forEach((el) => observer.observe(el));
}

// Hero slider: rotación automática entre las fotos reales del fraccionamiento,
// con puntos de navegación generados dinámicamente. Pausa la rotación si el
// visitante prefiere menos movimiento (prefers-reduced-motion).
function initHeroSlider() {
    const track = document.getElementById('hero-slider-track');
    const dotsWrap = document.getElementById('hero-slider-dots');
    if (!track || !dotsWrap) {
        return;
    }

    const slides = Array.from(track.querySelectorAll('.hero-slide'));
    if (slides.length < 2) {
        return;
    }

    let current = slides.findIndex((slide) => slide.classList.contains('is-active'));
    if (current < 0) {
        current = 0;
    }

    const dots = slides.map((_, index) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'dot-btn' + (index === current ? ' is-active' : '');
        dot.setAttribute('role', 'tab');
        dot.setAttribute('aria-label', 'Ir a la imagen ' + (index + 1));
        dot.addEventListener('click', () => goTo(index));
        dotsWrap.appendChild(dot);
        return dot;
    });

    function goTo(index) {
        slides[current].classList.remove('is-active');
        dots[current].classList.remove('is-active');
        current = index;
        slides[current].classList.add('is-active');
        dots[current].classList.add('is-active');
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!prefersReducedMotion) {
        setInterval(() => {
            goTo((current + 1) % slides.length);
        }, 6000);
    }
}

// Datos reales de noticias rescatadas del sitio anterior (PDFs/imagenes
// verificados uno por uno antes de usarse -- ver bitacora del proyecto).
// Los items sin documento/imagen confirmados muestran un aviso honesto en
// vez de contenido inventado.
const NEWS_CONTENT = {
    'asamblea-nov-2024': {
        title: 'Asamblea de asociados del 14 de noviembre del 2024',
        date: '13 de junio, 2025',
        html: '<p>El documento completo de esta asamblea aún no se ha rescatado del sitio anterior. Contáctanos si necesitas una copia.</p>',
    },
    'convocatoria-marzo-2025': {
        title: 'Convocatoria marzo 2025',
        date: '26 de marzo, 2025',
        html: '<p>El documento completo de esta convocatoria aún no se ha rescatado del sitio anterior. Contáctanos si necesitas una copia.</p>',
    },
    'convocatoria-segunda-asamblea': {
        title: 'Convocatoria segunda asamblea',
        date: '31 de enero, 2023',
        html: '<picture><source srcset="assets/img/noticia-convocatoria-segunda-asamblea.webp" type="image/webp"><img src="assets/img/noticia-convocatoria-segunda-asamblea.jpg" alt="Convocatoria a la Segunda Asamblea General Extraordinaria de Colonos"></picture><p><a class="btn" href="assets/docs/convocatoria-segunda-asamblea-2023.pdf" target="_blank" rel="noopener">Descargar PDF original</a></p>',
    },
    'proyecto-reforma': {
        title: 'Proyecto de reforma al reglamento interno FidePaz',
        date: '24 de enero, 2023',
        html: '<p>Documento rescatado del sitio anterior.</p><p><a class="btn" href="assets/docs/proyecto-reforma-reglamento-interno.pdf" target="_blank" rel="noopener">Descargar PDF original</a></p>',
    },
    'cuidemos-agua': {
        title: '¡Cuidemos el agua!',
        date: '24 de agosto, 2022',
        html: '<picture><source srcset="assets/img/noticia-cuidemos-el-agua.webp" type="image/webp"><img src="assets/img/noticia-cuidemos-el-agua.jpg" alt=""></picture><p>¡Todos podemos ayudar! ¡Cuidemos el agua!</p>',
    },
    'educando-mascota': {
        title: '¡Educando a mi mascota y a mí!',
        date: '8 de junio, 2022',
        html: '<p>El documento completo de este aviso aún no se ha rescatado del sitio anterior. Contáctanos si necesitas una copia.</p>',
    },
};

function initNewsModal() {
    const modal = document.getElementById('news-modal');
    const closeBtn = document.getElementById('news-modal-close');
    const titleEl = document.getElementById('news-modal-title');
    const dateEl = document.getElementById('news-modal-date');
    const bodyEl = document.getElementById('news-modal-body');
    const cards = document.querySelectorAll('[data-news]');
    if (!modal || !cards.length) {
        return;
    }

    function open(key) {
        const item = NEWS_CONTENT[key];
        if (!item) {
            return;
        }
        titleEl.textContent = item.title;
        dateEl.textContent = item.date;
        bodyEl.innerHTML = item.html;
        modal.hidden = false;
        closeBtn.focus();
    }

    function close() {
        modal.hidden = true;
    }

    cards.forEach((card) => {
        card.addEventListener('click', () => open(card.getAttribute('data-news')));
    });

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });
}

// Enfoque de navegación fluido: al saltar a una sección por ancla, se
// desplaza suavemente y se mueve el foco de teclado/lector de pantalla a esa
// sección para un comportamiento más parecido a una SPA.
function initSmoothNav() {
    document.querySelectorAll('a[href^="#"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const id = link.getAttribute('href').slice(1);
            const target = document.getElementById(id);
            if (!target) {
                return;
            }
            event.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            target.setAttribute('tabindex', '-1');
            target.focus({ preventScroll: true });
        });
    });
}

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
            const response = await fetch('api/v2/contact', {
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
