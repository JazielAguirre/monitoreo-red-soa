/*
 * Estados de las vistas que cargan datos (CLAUDE.md, "Estados de datos"):
 * - Cargando: esqueleto (viene en el HTML dentro de un <template>).
 * - Vacío: mensaje con una acción sugerida (sin datos, o los filtros no encuentran nada).
 * - Error: mensaje claro con el botón "Reintentar".
 * Todo el texto se inserta con textContent.
 */

function crearElemento(etiqueta, clase, texto = null) {
    const elemento = document.createElement(etiqueta);
    elemento.className = clase;

    if (texto !== null) {
        elemento.textContent = texto;
    }

    return elemento;
}

function crearIcono(clase) {
    const icono = document.createElement('i');
    icono.className = `bi ${clase}`;
    icono.setAttribute('aria-hidden', 'true');

    return icono;
}

/**
 * Pone en el contenedor el esqueleto de un <template> (por id).
 */
export function mostrarEsqueleto(contenedor, idPlantilla) {
    const plantilla = document.getElementById(idPlantilla);
    contenedor.replaceChildren(plantilla.content.cloneNode(true));
    contenedor.setAttribute('aria-busy', 'true');
}

/**
 * Crea un bloque de estado vacío.
 * variante: "exito" (verde, por ejemplo "Toda la red responde") o "neutro".
 * accion (opcional): { texto, icono, clase, alHacerClic }.
 */
export function crearVacio({ icono, variante = 'neutro', titulo, texto, accion = null, compacto = false }) {
    const bloque = crearElemento('div', [
        'estado-vacio',
        variante === 'neutro' ? 'estado-vacio--neutro' : '',
        compacto ? 'bloque-sin-borde' : '',
    ].filter(Boolean).join(' '));

    const circulo = crearElemento('span', 'estado-vacio__icono');
    circulo.append(crearIcono(icono));
    bloque.append(circulo, crearElemento('h3', 'estado-vacio__titulo', titulo), crearElemento('p', 'estado-vacio__texto', texto));

    if (accion !== null) {
        bloque.append(crearBoton(accion));
    }

    return bloque;
}

/**
 * Crea el bloque de error con "Reintentar".
 */
export function crearError({ titulo = 'No se pudieron cargar los datos', mensaje, alReintentar, compacto = false }) {
    const bloque = crearElemento('div', compacto ? 'vista-error bloque-sin-borde' : 'vista-error');
    bloque.setAttribute('role', 'alert');

    const circulo = crearElemento('span', 'vista-error__icono');
    circulo.append(crearIcono('bi-exclamation-triangle'));

    bloque.append(
        circulo,
        crearElemento('h3', 'vista-error__titulo', titulo),
        crearElemento('p', 'vista-error__texto', mensaje),
        crearBoton({ texto: 'Reintentar', icono: 'bi-arrow-clockwise', clase: 'btn btn-secondary btn-sm', alHacerClic: alReintentar }),
    );

    return bloque;
}

/**
 * Reemplaza el contenido del contenedor por un bloque (vacío o error) y quita aria-busy.
 */
export function mostrarBloque(contenedor, bloque) {
    contenedor.replaceChildren(bloque);
    contenedor.removeAttribute('aria-busy');
}

function crearBoton({ texto, icono = null, clase = 'btn btn-secondary btn-sm', alHacerClic = null, href = null }) {
    const boton = crearElemento(href ? 'a' : 'button', clase);

    if (href) {
        boton.href = href;
    } else {
        boton.type = 'button';
        boton.addEventListener('click', () => alHacerClic?.());
    }

    if (icono) {
        boton.append(crearIcono(icono));
    }
    boton.append(texto);

    return boton;
}
