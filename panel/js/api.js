// Cliente API del panel nuevo (2026-08-08). Reutiliza las mismas claves de
// localStorage que el bundle Angular heredado ("token", "activeUser") --
// mismo origen, así que iniciar sesión en un panel sirve para el otro
// mientras ambos coexistan durante la migración módulo por módulo.

const TOKEN_KEY = 'token';
const USER_KEY = 'activeUser';

// Prefijo de API relativo al origen actual: en producción esta página vive
// en https://v2.fidepaz.org/panel/..., en local en
// http://localhost/FidePaz.org/panel/... -- se calcula el prefijo real en
// vez de hardcodear el dominio (mismo patrón que reportes/estado-de-cuenta.html).
const API_BASE = (function () {
    const marker = '/panel/';
    const path = window.location.pathname;
    const idx = path.indexOf(marker);
    const root = idx >= 0 ? path.slice(0, idx) : '';
    return window.location.origin + root + '/api/v2';
})();

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function getActiveUser() {
    const raw = localStorage.getItem(USER_KEY);
    if (!raw) { return null; }
    try {
        return JSON.parse(raw);
    } catch (e) {
        return null;
    }
}

export function setSession(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
}

export function panelRoot() {
    const marker = '/panel/';
    const path = window.location.pathname;
    const idx = path.indexOf(marker);
    return idx >= 0 ? path.slice(0, idx + marker.length) : '/panel/';
}

export function goToLogin() {
    window.location.href = panelRoot() + 'login.html';
}

class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

async function request(method, path, { params, body } = {}) {
    let url = API_BASE + path;
    if (params) {
        const qs = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                qs.set(key, value);
            }
        });
        const qsString = qs.toString();
        if (qsString) { url += '?' + qsString; }
    }

    const headers = { 'Accept': 'application/json' };
    const token = getToken();
    if (token) { headers['Authorization'] = 'Bearer ' + token; }
    if (body !== undefined) { headers['Content-Type'] = 'application/json'; }

    let response;
    try {
        response = await fetch(url, {
            method,
            headers,
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
    } catch (networkError) {
        throw new ApiError('No se pudo conectar con el servidor. Revisa tu conexión.', 0);
    }

    if (response.status === 401) {
        clearSession();
        goToLogin();
        throw new ApiError('Sesión expirada.', 401);
    }

    let data = null;
    const contentType = response.headers.get('Content-Type') || '';
    if (contentType.includes('application/json')) {
        data = await response.json().catch(() => null);
    }

    if (!response.ok) {
        const message = (data && data.message) || 'Error del servidor (' + response.status + ').';
        throw new ApiError(message, response.status);
    }

    return data;
}

export const api = {
    get: (path, params) => request('GET', path, { params }),
    post: (path, body) => request('POST', path, { body }),
    put: (path, body) => request('PUT', path, { body }),
    del: (path) => request('DELETE', path),
};

export { ApiError };
