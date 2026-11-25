(() => {
    const formulario = document.querySelector('.registro-ui__form');
    if (!formulario) return;

    const obtener = selector =>
        formulario.querySelector(selector)?.value?.trim() || '';

    const metodo = () => {
        const valor = formulario.querySelector(
            'input[name="metodo_pago"]:checked'
        )?.value || 'EFECTIVO';

        return ({
            EFECTIVO: 'Efectivo',
            TRANSFERENCIA: 'Transferencia',
            TARJETA: 'Tarjeta / Terminal'
        })[valor] || valor;
    };

    const fecha = () => {
        const valor = obtener('#fecha');
        if (!valor) return 'No indicada';
        const [anio, mes, dia] = valor.split('-');
        return `${dia}/${mes}/${anio}`;
    };

    const nombreCliente = () =>
        document.getElementById('clienteSeleccionadoNombre')
            ?.textContent.trim() || 'Cliente no identificado';

    const datos = {
        cliente: [
            ['Nombre', nombreCliente],
            ['ID del cliente', () => obtener('#id_cliente') || 'No indicado'],
            ['ID del servicio', () => obtener('#id_servicio') || 'No indicado']
        ],
        pago: [
            ['Método de pago', metodo],
            ['Fecha', fecha],
            ['Referencia', () => obtener('#referencia') || 'Sin referencia']
        ],
        observaciones: () =>
            obtener('#observaciones') || 'Sin observaciones'
    };

    const modal = document.createElement('div');
    modal.className = 'confirmacion-pago-overlay';
    modal.hidden = true;

    modal.innerHTML = `
        <section class="confirmacion-pago-modal"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="confirmacionPagoTitulo">

            <header class="confirmacion-pago-cabecera">
                <div class="confirmacion-pago-icono">✓</div>
                <div class="confirmacion-pago-titulos">
                    <span class="confirmacion-pago-etiqueta">
                        REVISIÓN DEL REGISTRO
                    </span>
                    <h2 id="confirmacionPagoTitulo">Confirmar pago</h2>
                    <p>Verifica la información antes de continuar.</p>
                </div>
                <button type="button"
                        class="confirmacion-pago-cerrar"
                        aria-label="Cerrar ventana">×</button>
            </header>

            <div class="confirmacion-pago-contenido">

                <div class="confirmacion-pago-monto">
                    <span>MONTO TOTAL RECIBIDO</span>
                    <strong id="confirmacionMonto">$0.00</strong>
                    <div class="confirmacion-pago-metodo">
                        <span class="confirmacion-pago-metodo-icono">↗</span>
                        <span id="confirmacionMetodo">Efectivo</span>
                    </div>
                </div>

                <section class="confirmacion-pago-grupo">
                    <h3><span>01</span> Información del cliente</h3>
                    <div class="confirmacion-pago-datos" id="confirmacionCliente"></div>
                </section>

                <section class="confirmacion-pago-grupo">
                    <h3><span>02</span> Detalles del pago</h3>
                    <div class="confirmacion-pago-datos" id="confirmacionDetalles"></div>
                </section>

                <section class="confirmacion-pago-grupo"
                         id="confirmacionMensualidadGrupo" hidden>
                    <h3><span>03</span> Mensualidad y aplicación del pago</h3>
                    <div class="confirmacion-pago-datos"
                         id="confirmacionMensualidad"></div>
                </section>

                <section class="confirmacion-pago-observaciones">
                    <span>OBSERVACIONES</span>
                    <p id="confirmacionObservaciones"></p>
                </section>

                <div class="confirmacion-pago-nota">
                    <span>✓</span>
                    <p>Revisa los datos. El pago solo se registrará cuando
                    selecciones «Confirmar pago».</p>
                </div>
            </div>

            <footer class="confirmacion-pago-acciones">
                <button type="button" class="confirmacion-pago-editar">
                    Volver a editar
                </button>
                <button type="button" class="confirmacion-pago-aceptar">
                    Confirmar pago <span>→</span>
                </button>
            </footer>
        </section>
    `;

    document.body.appendChild(modal);

    const buscar = selector => modal.querySelector(selector);
    const botonEditar = buscar('.confirmacion-pago-editar');
    const botonCerrar = buscar('.confirmacion-pago-cerrar');
    const botonAceptar = buscar('.confirmacion-pago-aceptar');

    function crearFila(contenedor, etiqueta, obtenerValor) {
        const fila = document.createElement('div');
        fila.className = 'confirmacion-pago-fila';

        const titulo = document.createElement('span');
        titulo.textContent = etiqueta;

        const valor = document.createElement('strong');
        valor.textContent = obtenerValor();

        fila.append(titulo, valor);
        contenedor.appendChild(fila);
    }

    function dinero(valor) {
        return '$' + Number(valor).toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function actualizarResumenMensualidad(montoRecibido) {
        const grupo = buscar('#confirmacionMensualidadGrupo');
        const contenedor = buscar('#confirmacionMensualidad');
        const selector = formulario.querySelector('#id_obligacion');
        const opcion = selector?.selectedOptions[0];

        contenedor.replaceChildren();

        if (!selector?.value || !opcion?.dataset.monto) {
            grupo.hidden = true;
            botonAceptar.disabled = false;
            return;
        }

        const total = Number(opcion.dataset.monto);
        const aplicado = Number(opcion.dataset.aplicado);
        const saldo = Number(opcion.dataset.saldo);
        const abono = Number(montoRecibido);

        if (![total, aplicado, saldo, abono].every(Number.isFinite)) {
            grupo.hidden = true;
            botonAceptar.disabled = true;
            return;
        }

        const superaSaldo = abono > saldo;
        const restante = Math.max(0, saldo - abono);

        botonAceptar.disabled = !(abono > 0 && saldo > 0 && !superaSaldo);

        const filas = [
            ['Concepto', () => opcion.dataset.concepto || 'Mensualidad'],
            ['Periodo', () => opcion.dataset.periodo || 'Sin periodo'],
            ['Importe original', () => dinero(total)],
            ['Aplicado anteriormente', () => dinero(aplicado)],
            ['Saldo antes del pago', () => dinero(saldo)],
            ['Abono que se registrará', () => dinero(abono)],
            [
                'Saldo después del abono',
                () => superaSaldo ? 'No válido: supera el saldo' : dinero(restante)
            ]
        ];

        filas.forEach(([etiqueta, obtenerValor]) =>
            crearFila(contenedor, etiqueta, obtenerValor)
        );

        grupo.hidden = false;
    }

    function actualizarResumen() {
        const monto = obtener('#monto');

        buscar('#confirmacionMonto').textContent =
            monto && Number.isFinite(Number(monto))
                ? '$' + Number(monto).toLocaleString('es-MX', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })
                : '$0.00';

        buscar('#confirmacionMetodo').textContent = metodo();

        const cliente = buscar('#confirmacionCliente');
        const detalles = buscar('#confirmacionDetalles');

        cliente.replaceChildren();
        detalles.replaceChildren();

        datos.cliente.forEach(([etiqueta, obtenerValor]) =>
            crearFila(cliente, etiqueta, obtenerValor)
        );

        datos.pago.forEach(([etiqueta, obtenerValor]) =>
            crearFila(detalles, etiqueta, obtenerValor)
        );

        actualizarResumenMensualidad(monto);

        buscar('#confirmacionObservaciones').textContent =
            datos.observaciones();
    }

    function cerrarModal() {
        modal.hidden = true;
        document.body.classList.remove('confirmacion-pago-abierta');
    }

    formulario.addEventListener('submit', evento => {
        evento.preventDefault();
        actualizarResumen();

        modal.hidden = false;
        document.body.classList.add('confirmacion-pago-abierta');
        botonEditar.focus();
    });

    botonEditar.addEventListener('click', cerrarModal);
    botonCerrar.addEventListener('click', cerrarModal);

    modal.addEventListener('click', evento => {
        if (evento.target === modal) cerrarModal();
    });

    document.addEventListener('keydown', evento => {
        if (evento.key === 'Escape' && !modal.hidden) cerrarModal();
    });

    botonAceptar.addEventListener('click', () => {
        botonAceptar.disabled = true;
        botonAceptar.textContent = 'Registrando pago...';
        HTMLFormElement.prototype.submit.call(formulario);
    });
})();

/* UBB - Indicador dinámico de progreso del registro */
(() => {
    const formulario = document.querySelector('.registro-ui__form');
    if (!formulario) return;

    const contenedor = formulario.querySelector('.registro-ui__pasos');
    if (!contenedor) return;

    const pasos = [...contenedor.querySelectorAll('.registro-ui__paso')];
    const conectores = [...contenedor.querySelectorAll(':scope > i')];

    const cliente = formulario.querySelector('#id_cliente');
    const monto = formulario.querySelector('#monto');
    const fecha = formulario.querySelector('#fecha');
    const metodo = formulario.querySelector('input[name="metodo_pago"]');

    if (pasos.length !== 3 || !cliente || !monto || !fecha || !metodo) return;

    let etapaConfirmacion = false;

    function actualizarProgreso(etapaForzada = null) {
        let etapa = 1;

        const clienteValido =
            cliente.value.trim() !== '' &&
            cliente.validity.valid;

        if (clienteValido) etapa = 2;
        if (etapaConfirmacion) etapa = 3;
        if (etapaForzada !== null) etapa = etapaForzada;

        pasos.forEach((paso, indice) => {
            const numero = indice + 1;
            paso.classList.toggle('registro-ui__paso-activo', numero === etapa);
            paso.classList.toggle('registro-ui__paso-completado', numero < etapa);

            const circulo = paso.querySelector('span');
            if (circulo) {
                circulo.textContent = numero < etapa ? '✓' : String(numero);
            }
        });

        conectores.forEach((conector, indice) => {
            conector.classList.toggle(
                'registro-ui__conector-completado',
                indice + 1 < etapa
            );
        });
    }

    formulario.addEventListener('input', () => {
        if (!etapaConfirmacion) actualizarProgreso();
    });

    formulario.addEventListener('change', () => {
        if (!etapaConfirmacion) actualizarProgreso();
    });

    formulario.addEventListener('submit', () => {
        etapaConfirmacion = true;
        actualizarProgreso(3);
    });

    const botonEditar = document.querySelector('.confirmacion-pago-editar');
    const botonCerrar = document.querySelector('.confirmacion-pago-cerrar');

    [botonEditar, botonCerrar].forEach(boton => {
        boton?.addEventListener('click', () => {
            etapaConfirmacion = false;
            actualizarProgreso();
        });
    });

    document.addEventListener('keydown', evento => {
        if (evento.key === 'Escape') {
            etapaConfirmacion = false;
            actualizarProgreso();
        }
    });

    actualizarProgreso();
})();


/* UBB - Referencia adaptable al método de pago */
(() => {
    const formulario = document.querySelector('.registro-ui__form');
    if (!formulario) return;

    const campo = formulario.querySelector('.registro-ui__campo-referencia');
    const referencia = formulario.querySelector('#referencia');
    const etiqueta = campo?.querySelector('label');

    if (!campo || !referencia || !etiqueta) return;

    const ayuda = document.createElement('small');
    ayuda.className = 'registro-ui__referencia-ayuda';
    referencia.insertAdjacentElement('afterend', ayuda);

    const configuracion = {
        EFECTIVO: {
            visible: false,
            etiqueta: 'Referencia',
            placeholder: '',
            ayuda: ''
        },
        TRANSFERENCIA: {
            visible: true,
            etiqueta: 'Folio o referencia bancaria',
            placeholder: 'Ej. Folio de transferencia bancaria',
            ayuda: 'Captura el número de operación o folio del banco.'
        },
        TARJETA: {
            visible: true,
            etiqueta: 'Folio de autorización o ticket',
            placeholder: 'Ej. Número de autorización de la terminal',
            ayuda: 'Captura el folio de autorización o número de ticket.'
        }
    };

    function actualizarReferencia() {
        const seleccionado = formulario.querySelector(
            'input[name="metodo_pago"]:checked'
        )?.value || 'EFECTIVO';

        const datos = configuracion[seleccionado] || configuracion.EFECTIVO;

        campo.hidden = !datos.visible;

        const textoEtiqueta = [...etiqueta.childNodes]
            .find(nodo => nodo.nodeType === Node.TEXT_NODE);

        if (textoEtiqueta) {
            textoEtiqueta.nodeValue = datos.etiqueta + ' ';
        }

        referencia.placeholder = datos.placeholder;
        ayuda.textContent = datos.ayuda;
    }

    formulario.querySelectorAll('input[name="metodo_pago"]')
        .forEach(radio => radio.addEventListener('change', actualizarReferencia));

    actualizarReferencia();
})();
