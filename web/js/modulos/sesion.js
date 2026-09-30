/*
 * Navegación de la sesión (Fase 7): a dónde lleva el login y cómo se vuelve.
 * - Sin sesión, api.js manda a login.html?volver=<pantalla actual>.
 * - Después del login se vuelve a "volver" solo si es una pantalla de esta misma
 *   carpeta web/; cualquier otra cosa (otro sitio, //evil.com, javascript:…,
 *   rutas fuera del proyecto) lleva al Dashboard. Así el enlace del login no puede
 *   usarse para mandar a alguien a otra página (redirección abierta).
 * Las rutas se calculan desde la ubicación de este archivo, sin escribirlas fijas.
 */

const CARPETA_WEB = new URL('../../', import.meta.url); // …/monitoreo-red-soa/web/
const URL_LOGIN = new URL('login.html', CARPETA_WEB);
const URL_DASHBOARD = new URL('index.html', CARPETA_WEB);
let saliendo = false; // tras "Salir", los 401 que lleguen tarde no deben volver a redirigir

export function estoyEnElLogin() {
    return location.pathname === URL_LOGIN.pathname;
}

/**
 * Ruta (del mismo origen) a la que se puede volver después del login; si "volver" no es
 * una pantalla de web/, la del Dashboard.
 */
export function destinoSeguro(volver) {
    const dashboard = URL_DASHBOARD.pathname;

    if (typeof volver !== 'string' || volver === '') {
        return dashboard;
    }

    let url;
    try {
        url = new URL(volver, CARPETA_WEB.origin); // normaliza "..", "%2e%2e" y "\"
    } catch {
        return dashboard;
    }

    const dentroDeWeb = url.origin === CARPETA_WEB.origin && url.pathname.startsWith(CARPETA_WEB.pathname);

    return dentroDeWeb && url.pathname !== URL_LOGIN.pathname ? url.pathname + url.search + url.hash : dashboard;
}

/**
 * Lleva al login recordando la pantalla actual. No hace nada si ya se está en el login.
 */
export function irAlLogin() {
    if (estoyEnElLogin() || saliendo) {
        return;
    }
    const url = new URL(URL_LOGIN);
    url.searchParams.set('volver', location.pathname + location.search + location.hash);
    location.replace(url.href);
}

/**
 * Lleva al login sin "volver" (después de "Salir").
 */
export function irAlLoginSinVolver() {
    saliendo = true;
    location.replace(URL_LOGIN.href);
}
