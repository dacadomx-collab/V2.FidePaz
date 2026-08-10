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

export function apiUrl(path) {
    return API_BASE + path;
}

function buildUrl(path, params) {
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
    return url;
}

async function doFetch(url, options) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    const token = getToken();
    if (token) { headers.Authorization = 'Bearer ' + token; }

    let response;
    try {
        response = await fetch(url, { ...options, headers });
    } catch (networkError) {
        throw new ApiError('No se pudo conectar con el servidor. Revisa tu conexión.', 0);
    }

    if (response.status === 401) {
        clearSession();
        goToLogin();
        throw new ApiError('Sesión expirada.', 401);
    }

    return response;
}

async function request(method, path, { params, body } = {}) {
    const url = buildUrl(path, params);
    const headers = {};
    if (body !== undefined) { headers['Content-Type'] = 'application/json'; }

    const response = await doFetch(url, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

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

// Subida de archivos (multipart/form-data) -- nunca fija Content-Type a
// mano, el navegador lo hace solo con el boundary correcto para FormData.
async function requestUpload(path, formData) {
    const response = await doFetch(buildUrl(path), { method: 'POST', body: formData });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        throw new ApiError((data && data.message) || 'Error al subir el archivo.', response.status);
    }
    return data;
}

// Descarga autenticada de un binario (comprobantes, .xlsx, etc.) -- un
// <a href> plano no puede mandar el header Authorization, así que se pide
// por fetch y se devuelve como Blob para abrir/descargar en el cliente.
async function requestBlob(path) {
    const response = await doFetch(buildUrl(path), { method: 'GET' });
    if (!response.ok) {
        const data = await response.json().catch(() => null);
        throw new ApiError((data && data.message) || 'No se pudo obtener el archivo.', response.status);
    }
    return response.blob();
}

export const api = {
    get: (path, params) => request('GET', path, { params }),
    post: (path, body) => request('POST', path, { body }),
    put: (path, body) => request('PUT', path, { body }),
    del: (path) => request('DELETE', path),
    upload: (path, formData) => requestUpload(path, formData),
    getBlob: (path) => requestBlob(path),
};

export { ApiError };
