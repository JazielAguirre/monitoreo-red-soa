/*
 * Demostraciones de web/componentes.html (página de muestra del sistema de diseño):
 * lanza notificaciones reales arriba a la derecha. El modal se abre solo con los
 * atributos data-bs-* de Bootstrap.
 * En la Fase 6, mostrarNotificacion() pasa al módulo común de la interfaz.
 */

'use strict';

const NOTIFICACIONES_DE_EJEMPLO = {
    exito: {
        variante: 'notificacion--exito',
        icono: 'bi-check-lg',
        titulo: 'Monitoreo completado',
        texto: '4 de 6 dispositivos responden.',
    },
    error: {
        variante: 'notificacion--error',
        icono: 'bi-wifi-off',
        titulo: 'Servidor principal no respondió',
        texto: 'Se generó una alerta pendiente.',
    },
    info: {
        variante: '',
        icono: 'bi-plus-lg',
        titulo: 'Dispositivo agregado',
        texto: 'Impresora Laboratorio se registró correctamente.',
    },
};

/**
 * Tiempo visible de una notificación, leído del token --duracion-notificacion
 * (por ejemplo "4.5s"), para no escribirlo dos veces.
 */
function duracionNotificacionMs() {
    const valor = getComputedStyle(document.documentElement).getPropertyValue('--duracion-notificacion').trim();
    const segundos = parseFloat(valor);

    return Number.isFinite(segundos) ? segundos * 1000 : 4500;
}

/**
 * Muestra una notificación arriba a la derecha y la elimina del DOM al ocultarse.
 */
function mostrarNotificacion({ variante, icono, titulo, texto }) {
    const plantilla = document.getElementById('plantilla-notificacion');
    const contenedor = document.querySelector('.toast-container.notificaciones');
    const toast = plantilla.content.firstElementChild.cloneNode(true);

    const notificacion = toast.querySelector('.notificacion');
    if (variante) {
        notificacion.classList.add(variante);
    }
    toast.querySelector('.notificacion__icono .bi').classList.add(icono);
    toast.querySelector('.notificacion__titulo').textContent = titulo;
    toast.querySelector('.notificacion__texto').textContent = texto;

    contenedor.append(toast);
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    bootstrap.Toast.getOrCreateInstance(toast, { delay: duracionNotificacionMs() }).show();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-notificacion]').forEach((boton) => {
        boton.addEventListener('click', () => {
            mostrarNotificacion(NOTIFICACIONES_DE_EJEMPLO[boton.dataset.notificacion]);
        });
    });
});
