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
});

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
