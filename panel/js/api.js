// Cliente API del panel nuevo (2026-08-08). Reutiliza las mismas claves de
// localStorage que el bundle Angular heredado ("token", "activeUser") --
// mismo origen, así que iniciar sesión en un panel sirve para el otro
// mientras ambos coexistan durante la migración módulo por módulo.

const TOKEN_KEY = 'token';
const USER_KEY = 'activeUser';

// Estandarización global de fechas (2026-08-14, Objetivo 3): "DD MMM YYYY"
// con mes de 3 letras en mayúsculas y en español (ej. "13 AGO 2026") -- el
// mismo formato en todas las vistas del panel y en el Recibo Oficial
// (contraparte server-side: caja_format_date_colono() en
// api/v2/routes/caja.php, mismo criterio de no depender del locale). Las
// fechas de MySQL llegan sin sufijo de zona horaria (ej.
// "2026-08-10T00:00:00"), así que `new Date(...)` las interpreta en hora
// LOCAL del navegador -- no hay que compensar UTC a mano.
const MESES_ABREVIADOS = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

export function formatDateColono(dateString) {
    if (!dateString) { return ''; }
    const d = new Date(dateString);
    if (Number.isNaN(d.getTime())) { return ''; }
    const day = String(d.getDate()).padStart(2, '0');
    return `${day} ${MESES_ABREVIADOS[d.getMonth()]} ${d.getFullYear()}`;
}

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

// Actualiza campos sueltos del usuario cacheado sin tocar el token -- para
// reflejar en la UI, al instante y sin relogin, cambios que el propio
// usuario acaba de confirmar en el servidor (ej. aceptar el aviso de
// privacidad).
export function patchActiveUser(patch) {
    const user = getActiveUser();
    if (!user) { return; }
    localStorage.setItem(USER_KEY, JSON.stringify({ ...user, ...patch }));
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

// Convierte un archivo_pdf_url guardado en `announcements` a una URL de
// navegador correcta desde cualquier página bajo /panel/*.html.
//
// Dos convenciones distintas conviven hoy en la misma columna (2026-08-12):
//   1) Datos ya existentes (importados antes de esta sesión): guardados como
//      "../assets/docs/reports/archivo.pdf" -- el "../" YA viene incluido.
//   2) POST /announcements/upload (nuevo, esta sesión): guarda
//      "assets/uploads/comunicados/archivo.pdf" -- SIN "../", relativo a la
//      raíz del proyecto.
// Un solo "../" hardcodeado en el HTML (`href="../${url}"`) solo resuelve
// bien el caso 2 -- para el caso 1 duplica el "../" y la URL apunta un nivel
// arriba de lo que debería. Esta función detecta cuál convención trae el
// valor y arma la URL correcta para ambas sin tener que normalizar los 59+
// registros ya existentes en la base de datos real.
export function resolveDocUrl(archivoUrl) {
    if (!archivoUrl) { return ''; }
    return archivoUrl.startsWith('../') ? archivoUrl : panelRoot() + '../' + archivoUrl;
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

    // Un 401 de /auth/login significa "credenciales inválidas" (endpoint sin
    // sesión previa que proteger) -- NO "tu sesión expiró". Tratarlo igual
    // que cualquier otro 401 disparaba clearSession()+goToLogin() sobre la
    // propia página de login: navegación forzada de vuelta a login.html
    // ANTES de que el catch() del formulario alcanzara a mostrar el mensaje
    // real, así que un intento fallido se veía como un refresh silencioso
    // sin explicación (reporte de campo 2026-08-11).
    const isLoginAttempt = url.indexOf('/auth/login') !== -1;
    if (response.status === 401 && !isLoginAttempt) {
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

// Historial de los intentos de solucionar el bloqueo de pop-ups en "Ver
// comprobante"/"Ver recibo oficial" (2026-08-14), por si hace falta este
// contexto en el futuro: primero se probó window.open() síncrono antes del
// fetch (no bastó -- Chrome lo seguía bloqueando en el navegador real de un
// colono); después un patrón de "botón -> Cargando... -> se reemplaza por
// un <a> real" (funcionaba, pero costaba un clic extra). La solución
// definitiva no vive aquí: /payment/owners, /payment/filter y
// /payment/quotas-owners/{id} ahora traen, por cada cuota, un token JWT de
// vida corta (15 min) ya escopeado a ESA cuota exacta
// (quota_view_token_issue() en api/v2/routes/quotas.php), y el frontend
// arma un <a href="...&token=..."> directo desde el primer render de la
// fila -- un solo clic normal, sin fetch ni window.open() de por medio,
// así que ningún bloqueador de pop-ups puede interferir jamás. Ver
// panel/mi-cuenta.html, panel/pagos.html y reportes/estado-de-cuenta.html.

export { ApiError };
