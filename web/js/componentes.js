/*
 * Demostraciones de web/componentes.html (página de muestra del sistema de diseño):
 * lanza notificaciones reales y muestra el bloque de error con "Reintentar".
 * El modal se abre solo con los atributos data-bs-* de Bootstrap.
 */

import { mostrarNotificacion } from './modulos/notificaciones.js';
import { crearError, mostrarBloque } from './modulos/vistas.js';
import { actualizarContadorAlertas, llenarBarraLateral, llenarEncabezadoMovil } from './modulos/estructura.js';

const NOTIFICACIONES_DE_EJEMPLO = {
    exito: { tipo: 'exito', titulo: 'Monitoreo completado', texto: '4 de 6 dispositivos responden.' },
    error: {
        tipo: 'error',
        icono: 'bi-wifi-off',
        titulo: 'Servidor principal no respondió',
        texto: 'Se generó una alerta pendiente.',
        enlace: { texto: 'Ver alerta', href: 'alertas.html' },
    },
    info: { tipo: 'info', icono: 'bi-plus-lg', titulo: 'Dispositivo agregado', texto: 'Impresora Laboratorio se registró correctamente.' },
};

function mostrarErrorDeEjemplo(contenedor) {
    mostrarBloque(contenedor, crearError({
        titulo: 'No se pudieron cargar los dispositivos',
        mensaje: 'No se pudo conectar con el servidor. Revisa que Apache esté encendido.',
        alReintentar: () => mostrarNotificacion({ tipo: 'info', titulo: 'Reintentando…', texto: 'En las pantallas, aquí se vuelven a pedir los datos.' }),
    }));
}

document.querySelectorAll('[data-notificacion]').forEach((boton) => {
    boton.addEventListener('click', () => mostrarNotificacion(NOTIFICACIONES_DE_EJEMPLO[boton.dataset.notificacion]));
});

const contenedorError = document.getElementById('demo-error');
if (contenedorError) {
    mostrarErrorDeEjemplo(contenedorError);
}

const barraDemo = document.getElementById('demo-barra-lateral');
const encabezadoDemo = document.getElementById('demo-encabezado-movil');
if (barraDemo && encabezadoDemo) {
    llenarBarraLateral(barraDemo, 'index');
    llenarEncabezadoMovil(encabezadoDemo, 'index');
    // El menú se muestra abierto, como en 1d, sin mover el foco (no debe desplazar la página).
    const marco = encabezadoDemo.parentElement;
    marco.querySelector('.menu-movil').hidden = false;
    marco.querySelector('.menu-movil__fondo').hidden = false;
    const boton = encabezadoDemo.querySelector('.encabezado-movil__boton');
    boton.setAttribute('aria-expanded', 'true');
    boton.setAttribute('aria-label', 'Cerrar el menú');
    boton.firstElementChild.className = 'bi bi-x-lg';
    actualizarContadorAlertas();
}
