// Notificaciones toast reutilizables (2026-08-08). Un solo stack fijo al
// pie de la pantalla, se crea perezosamente la primera vez que se llama.

let stack = null;

function ensureStack() {
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'panel-toast-stack';
        document.body.appendChild(stack);
    }
    return stack;
}

export function toast(message, { type = 'success', duration = 4000 } = {}) {
    const el = document.createElement('div');
    el.className = 'panel-toast' + (type === 'error' ? ' error' : '');
    el.textContent = message;
    ensureStack().appendChild(el);
    setTimeout(() => el.remove(), duration);
}
