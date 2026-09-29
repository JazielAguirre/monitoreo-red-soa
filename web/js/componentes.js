/*
 * Demostraciones de web/componentes.html (página de muestra del sistema de diseño):
 * lanza notificaciones reales y muestra el bloque de error con "Reintentar".
 * El modal se abre solo con los atributos data-bs-* de Bootstrap.
 */

import { mostrarNotificacion } from './modulos/notificaciones.js';
import { crearError, mostrarBloque } from './modulos/vistas.js';
import { actualizarContadorAlertas, llenarBarraLateral, llenarEncabezadoMovil } from './modulos/estructura.js';
import { crearProgreso } from './modulos/monitorear-todos.js';
import { crearGraficaArea, crearGraficaLineas, estiloSerie } from './modulos/graficas.js';

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

// Progreso como en 2d: dos comprobados, el tercero en curso y el resto en espera.
const contenedorProgreso = document.getElementById('demo-progreso');
if (contenedorProgreso) {
    const lista = [
        { nombre: 'Mi PC', ip: '127.0.0.1' },
        { nombre: 'Router principal', ip: '192.168.1.1' },
        { nombre: 'Servidor principal', ip: '192.168.1.10' },
        { nombre: 'Servidor de respaldo', ip: '192.168.1.11' },
        { nombre: 'Impresora Laboratorio', ip: '192.168.1.20' },
        { nombre: 'PC Recepción', ip: '192.168.1.35' },
    ];
    const progreso = crearProgreso(contenedorProgreso);
    progreso.mostrar(lista);
    progreso.actualizar(2, {
        0: { monitoreo: { estado: 'activo', tiempo_respuesta_ms: 0.31 } },
        1: { monitoreo: { estado: 'activo', tiempo_respuesta_ms: 1.9 } },
    });
}

// Gráfica de ejemplo con cinco series: la quinta repite el primer color con línea punteada.
const lienzoDemo = document.getElementById('demo-grafica');
if (lienzoDemo && window.Chart) {
    const series = [
        { nombre: 'Router principal', datos: [1.6, 1.9, 1.7, 2.1, 1.8, 1.7, 2.4, 1.9, 1.7, 1.8] },
        { nombre: 'Impresora Laboratorio', datos: [3.8, 4.4, 4.0, 5.2, 4.6, 3.9, 4.3, 4.8, 4.2, 4.1] },
        { nombre: 'PC Recepción', datos: [2.2, 2.6, 2.4, 2.3, 3.1, 2.7, 2.5, 2.4, 2.8, 2.5] },
        { nombre: 'Mi PC', datos: [0.3, 0.28, 0.35, 0.31, 0.3, 0.34, 0.29, 0.33, 0.3, 0.32] },
        { nombre: 'Servidor de archivos', datos: [5.1, 5.6, null, null, 5.9, 5.4, 5.2, 5.8, 5.5, 5.3] },
    ];
    document.getElementById('demo-leyenda').replaceChildren(...series.map((serie, i) => {
        const estilo = estiloSerie(i);
        const item = document.createElement('li');
        item.className = `leyenda-grafica__item${estilo.punteada ? ' leyenda-grafica__item--punteada' : ''}`;
        const linea = document.createElement('span');
        linea.className = 'leyenda-grafica__linea';
        linea.style.setProperty('--color-serie', `var(${estilo.variable})`);
        item.append(linea, serie.nombre);
        return item;
    }));
    const etiquetas = ['09:20', '09:25', '09:30', '09:35', '09:40', '09:45', '09:50', '09:55', '10:00', '10:05'];
    crearGraficaLineas(lienzoDemo, { etiquetas, series });
}

// Gráfica de un dispositivo como en 3a: cuatro comprobaciones sin respuesta al final.
const lienzoArea = document.getElementById('demo-grafica-area');
if (lienzoArea && window.Chart) {
    const tiempos = [0.8, 1.2, 0.9, 2.4, 1.0, 0.95, null, null, null, null];
    crearGraficaArea(lienzoArea, {
        puntos: tiempos.map((ms, i) => ({ etiqueta: `09:${String(i * 5).padStart(2, '0')}`, ms })),
    });
}
