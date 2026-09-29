/*
 * Notificaciones (toasts) arriba a la derecha, con el componente "Notificaciones"
 * del sistema de diseño. El contenido se inserta con textContent, nunca como HTML.
 */

const ICONOS_POR_TIPO = {
    exito: 'bi-check-lg',
    error: 'bi-exclamation-circle',
    info: 'bi-info-lg',
};

const VARIANTES_POR_TIPO = {
    exito: 'notificacion--exito',
    error: 'notificacion--error',
    info: '',
};

/**
 * Tiempo visible, leído del token --duracion-notificacion (por ejemplo "4.5s").
 */
function duracionMs() {
    const valor = getComputedStyle(document.documentElement).getPropertyValue('--duracion-notificacion').trim();
    const segundos = parseFloat(valor);

    return Number.isFinite(segundos) ? segundos * 1000 : 4500;
}

/**
 * Devuelve el contenedor de notificaciones, creándolo la primera vez.
 */
function contenedorNotificaciones() {
    let contenedor = document.querySelector('.toast-container.notificaciones');

    if (contenedor === null) {
        contenedor = document.createElement('div');
        contenedor.className = 'toast-container notificaciones position-fixed';
        contenedor.setAttribute('aria-live', 'polite');
        contenedor.setAttribute('aria-atomic', 'false');
        document.body.append(contenedor);
    }

    return contenedor;
}

function crearElemento(etiqueta, clase, texto = null) {
    const elemento = document.createElement(etiqueta);
    elemento.className = clase;

    if (texto !== null) {
        elemento.textContent = texto;
    }

    return elemento;
}

/**
 * Muestra una notificación.
 * tipo: "exito", "error" o "info". icono (opcional): clase de Bootstrap Icons.
 * enlace (opcional): { texto, href }, por ejemplo "Ver alerta".
 */
export function mostrarNotificacion({ tipo = 'info', icono = null, titulo, texto = '', enlace = null }) {
    const toast = crearElemento('div', 'toast');
    toast.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-atomic', 'true');

    const notificacion = crearElemento('div', `notificacion ${VARIANTES_POR_TIPO[tipo] ?? ''}`.trim());

    const circulo = crearElemento('span', 'notificacion__icono');
    const icon = crearElemento('i', `bi ${icono ?? ICONOS_POR_TIPO[tipo] ?? ICONOS_POR_TIPO.info}`);
    icon.setAttribute('aria-hidden', 'true');
    circulo.append(icon);

    const cuerpo = crearElemento('div', 'notificacion__cuerpo');
    cuerpo.append(crearElemento('span', 'notificacion__titulo', titulo));

    const detalle = crearElemento('span', 'notificacion__texto', texto);
    if (enlace !== null) {
        const a = crearElemento('a', '', enlace.texto);
        a.href = enlace.href;
        detalle.append(texto ? ' ' : '', a);
    }
    cuerpo.append(detalle);

    const cerrar = crearElemento('button', 'notificacion__cerrar');
    cerrar.type = 'button';
    cerrar.setAttribute('data-bs-dismiss', 'toast');
    cerrar.setAttribute('aria-label', 'Cerrar notificación');
    const x = crearElemento('i', 'bi bi-x-lg');
    x.setAttribute('aria-hidden', 'true');
    cerrar.append(x);

    notificacion.append(circulo, cuerpo, cerrar);
    toast.append(notificacion);
    contenedorNotificaciones().append(toast);

    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    window.bootstrap.Toast.getOrCreateInstance(toast, { delay: duracionMs() }).show();

    return toast;
}

/**
 * Notificación de error a partir de un ErrorApi (o cualquier error).
 */
export function notificarError(error, titulo = 'No se pudo completar la acción') {
    return mostrarNotificacion({
        tipo: 'error',
        titulo,
        texto: error?.message || 'Ocurrió un error inesperado.',
    });
}
