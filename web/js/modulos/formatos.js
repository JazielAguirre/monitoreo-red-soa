/*
 * Formatos de la interfaz: fechas, tiempos de respuesta, porcentajes y escapado de HTML.
 *
 * Las fechas de la API ("YYYY-MM-DD HH:MM:SS", hora local de America/Mazatlan) se
 * separan a mano: new Date() con ese texto da una fecha inválida en Safari del iPhone.
 */

const PATRON_FECHA_API = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/;

/**
 * Separa una fecha de la API en sus partes (como texto, con ceros), o null si no es válida.
 */
export function partesFecha(texto) {
    const coincidencia = typeof texto === 'string' ? PATRON_FECHA_API.exec(texto) : null;

    if (coincidencia === null) {
        return null;
    }

    const [, anio, mes, dia, hora, minuto, segundo] = coincidencia;

    return { anio, mes, dia, hora, minuto, segundo };
}

/**
 * Indica si la fecha de la API es del mismo día que ahora (hora del equipo).
 */
export function esHoy(partes, ahora = new Date()) {
    return Number(partes.anio) === ahora.getFullYear()
        && Number(partes.mes) === ahora.getMonth() + 1
        && Number(partes.dia) === ahora.getDate();
}

/**
 * "Hoy, 10:05" si es de hoy; "21/09/2026 10:05" si es de otro día.
 * Si no hay fecha, devuelve siVacio (por ejemplo "Nunca").
 */
export function formatearFechaCorta(texto, { ahora = new Date(), siVacio = '—' } = {}) {
    const p = partesFecha(texto);

    if (p === null) {
        return siVacio;
    }

    return esHoy(p, ahora) ? `Hoy, ${p.hora}:${p.minuto}` : `${p.dia}/${p.mes}/${p.anio} ${p.hora}:${p.minuto}`;
}

/**
 * "21/09/2026 10:05" siempre (historial, alertas).
 */
export function formatearFechaHora(texto, siVacio = '—') {
    const p = partesFecha(texto);

    return p === null ? siVacio : `${p.dia}/${p.mes}/${p.anio} ${p.hora}:${p.minuto}`;
}

/**
 * "21/09/2026 · 10:05:12" ("Última comprobación").
 */
export function formatearFechaCompleta(texto, siVacio = '—') {
    const p = partesFecha(texto);

    return p === null ? siVacio : `${p.dia}/${p.mes}/${p.anio} · ${p.hora}:${p.minuto}:${p.segundo}`;
}

/**
 * "10:05" (etiquetas de las gráficas).
 */
export function formatearHora(texto, siVacio = '') {
    const p = partesFecha(texto);

    return p === null ? siVacio : `${p.hora}:${p.minuto}`;
}

/**
 * Compara dos fechas de la API (sirven como texto porque tienen ancho fijo).
 */
export function compararFechas(a, b) {
    return String(a ?? '').localeCompare(String(b ?? ''));
}

/**
 * Tiempo de respuesta: 0 → "< 1 ms"; 1.8 → "1.8 ms"; 0.32 → "0.32 ms".
 * null (no respondió) → siVacio.
 */
export function formatearMs(valor, siVacio = 'Sin respuesta') {
    if (typeof valor !== 'number' || !Number.isFinite(valor)) {
        return siVacio;
    }

    return valor === 0 ? '< 1 ms' : `${Number(valor.toFixed(2))} ms`;
}

/**
 * "96.2 %" / "67 %".
 */
export function formatearPorcentaje(valor, decimales = 0, siVacio = '—') {
    if (typeof valor !== 'number' || !Number.isFinite(valor)) {
        return siVacio;
    }

    return `${Number(valor.toFixed(decimales))} %`;
}

/**
 * Fecha del equipo como "YYYY-MM-DD" para un <input type="date">.
 * Usa las partes locales, no toISOString() (que convierte a UTC).
 */
export function fechaParaCampo(fecha) {
    const mes = String(fecha.getMonth() + 1).padStart(2, '0');
    const dia = String(fecha.getDate()).padStart(2, '0');

    return `${fecha.getFullYear()}-${mes}-${dia}`;
}

/**
 * La fecha de hace n días (a la misma hora).
 */
export function haceDias(n, desde = new Date()) {
    const fecha = new Date(desde.getFullYear(), desde.getMonth(), desde.getDate());
    fecha.setDate(fecha.getDate() - n);

    return fecha;
}

/**
 * Escapa un texto para insertarlo en HTML. Los datos de la API se insertan con
 * textContent o pasan por aquí; nunca van directo a innerHTML.
 */
export function escaparHtml(texto) {
    return String(texto ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

/**
 * Texto en minúsculas y sin acentos, para buscar ("Recepción" encuentra "recepcion").
 */
export function normalizarBusqueda(texto) {
    return String(texto ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

/**
 * "1 dispositivo" / "3 dispositivos".
 */
export function pluralizar(cantidad, singular, plural) {
    return `${cantidad} ${cantidad === 1 ? singular : plural}`;
}
