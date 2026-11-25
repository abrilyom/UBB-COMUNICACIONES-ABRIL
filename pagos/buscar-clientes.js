(() => {
  const buscador = document.getElementById('buscarClienteRemoto');
  const estado = document.getElementById('estadoBusquedaCliente');
  const resultados = document.getElementById('resultadosClienteRemoto');
  const campoCliente = document.getElementById('id_cliente');
  const campoServicio = document.getElementById('id_servicio');
  const campoReferenciaUbb = document.getElementById('referencia_ubb');
  const campoMonto = document.getElementById('monto');

  const clienteVacio = document.getElementById('clienteVacio');
  const clienteSeleccionado = document.getElementById('clienteSeleccionado');
  const cambiarCliente = document.getElementById('cambiarCliente');

  if (!buscador || !estado || !resultados || !campoCliente || !campoServicio || !campoReferenciaUbb) return;

  let temporizador;
  let solicitud = 0;
  let serviciosCliente = null;
  let solicitudReferencia = 0;
  let referenciaBaseActual = '';

  function limpiarReferenciaUbb() {
    solicitudReferencia++;
    referenciaBaseActual = '';
    campoReferenciaUbb.value = '';
  }

  const contenedorObligaciones = document.getElementById('contenedorObligaciones');
  const campoObligacion = document.getElementById('id_obligacion');
  const estadoObligaciones = document.getElementById('estadoObligaciones');
  let solicitudObligaciones = 0;

  function limpiarObligaciones() {
    solicitudObligaciones++;
    if (campoObligacion) {
      campoObligacion.replaceChildren();
      const opcion = document.createElement('option');
      opcion.value = '';
      opcion.textContent = 'Selecciona una mensualidad';
      campoObligacion.appendChild(opcion);
      campoObligacion.value = '';
    }
    if (contenedorObligaciones) contenedorObligaciones.hidden = true;
    if (estadoObligaciones) estadoObligaciones.textContent = '';
    if (campoMonto) campoMonto.value = '';
  }

  if (campoObligacion && campoMonto) {
    campoObligacion.addEventListener('change', () => {
      const opcion = campoObligacion.selectedOptions[0];
      const saldo = opcion?.dataset.saldo;

      if (!campoObligacion.value || saldo === undefined) {
        campoMonto.value = '';
        campoReferenciaUbb.value = referenciaBaseActual;
        return;
      }

      campoReferenciaUbb.value = opcion?.dataset.referencia || referenciaBaseActual;

      const saldoNumerico = Number(saldo);

      campoMonto.value =
        Number.isFinite(saldoNumerico) && saldoNumerico > 0
          ? saldoNumerico.toFixed(2)
          : '';
    });
  }

  // Evitar que la rueda del mouse cambie accidentalmente el monto.
  if (campoMonto) {
    campoMonto.addEventListener('wheel', (evento) => {
      evento.preventDefault();
    }, { passive: false });
  }

  async function consultarObligaciones(idCliente, idServicio) {
    if (!contenedorObligaciones || !campoObligacion || !estadoObligaciones) return;

    const actual = ++solicitudObligaciones;
    limpiarObligaciones();
    const consulta = ++solicitudObligaciones;

    contenedorObligaciones.hidden = false;
    estadoObligaciones.textContent = 'Consultando mensualidades pendientes...';

    try {
      const parametros = new URLSearchParams({
        obtener_obligaciones: '1',
        id_cliente: String(idCliente),
        id_servicio: String(idServicio)
      });

      const respuesta = await fetch('registrar.php?' + parametros.toString(), {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const datos = await respuesta.json();

      if (
        consulta !== solicitudObligaciones ||
        String(campoCliente.value) !== String(idCliente) ||
        String(campoServicio.value) !== String(idServicio)
      ) return;

      if (!respuesta.ok || !datos.ok) {
        throw new Error(datos.mensaje || 'No se pudieron consultar las mensualidades.');
      }

      campoObligacion.replaceChildren();
      const inicial = document.createElement('option');
      inicial.value = '';
      inicial.textContent = 'Selecciona una mensualidad';
      campoObligacion.appendChild(inicial);

      const dinero = valor => Number(valor).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN'
      });

      (datos.obligaciones || []).forEach(obligacion => {
        const opcion = document.createElement('option');
        opcion.value = String(obligacion.id_obligacion);
        opcion.textContent =
          obligacion.concepto + ' · ' +
          (obligacion.periodo || 'Sin periodo') + ' · Saldo: ' +
          dinero(obligacion.saldo);
        opcion.dataset.saldo = String(obligacion.saldo);
        opcion.dataset.periodo = String(obligacion.periodo || '');
        opcion.dataset.concepto = String(obligacion.concepto || '');
        opcion.dataset.monto = String(obligacion.monto);
        opcion.dataset.aplicado = String(obligacion.aplicado);
        opcion.dataset.referencia = String(obligacion.referencia_cobro || '');
        campoObligacion.appendChild(opcion);
      });

      estadoObligaciones.textContent = datos.obligaciones.length
        ? datos.obligaciones.length + ' obligación(es) pendiente(s).'
        : 'Este servicio no tiene mensualidades pendientes.';
    } catch (error) {
      if (consulta !== solicitudObligaciones) return;
      estadoObligaciones.textContent = error.message || 'No se pudieron consultar las mensualidades.';
    }
  }

  async function consultarReferenciaUbb(idCliente, idServicio) {
    const actual = ++solicitudReferencia;
    referenciaBaseActual = '';
    campoReferenciaUbb.value = 'Consultando referencia...';

    try {
      const parametros = new URLSearchParams({
        obtener_referencia_ubb: '1',
        id_cliente: String(idCliente),
        id_servicio: String(idServicio)
      });

      const respuesta = await fetch('registrar.php?' + parametros.toString(), {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const datos = await respuesta.json();

      if (
        actual !== solicitudReferencia ||
        String(campoCliente.value) !== String(idCliente) ||
        String(campoServicio.value) !== String(idServicio)
      ) return;

      if (!respuesta.ok || !datos.ok || !datos.referencia_base) {
        referenciaBaseActual = '';
        campoReferenciaUbb.value = '';
        estado.textContent = datos.mensaje || 'No se pudo obtener la referencia UBB.';
        return;
      }

      referenciaBaseActual = datos.referencia_base;
      if (!campoObligacion?.value) {
        campoReferenciaUbb.value = referenciaBaseActual;
      }
    } catch (error) {
      if (actual !== solicitudReferencia) return;
      referenciaBaseActual = '';
      campoReferenciaUbb.value = '';
      estado.textContent = 'No se pudo consultar la referencia UBB.';
    }
  }

  const texto = (valor, alternativo = '') =>
    valor !== null && valor !== undefined && String(valor).trim() !== ''
      ? String(valor).trim()
      : alternativo;

  function elemento(tag, clase, contenido) {
    const nodo = document.createElement(tag);
    nodo.className = clase;
    nodo.textContent = contenido;
    return nodo;
  }

  function nombreCompleto(item) {
    return [
      item.nombre,
      item.apellido_paterno,
      item.apellido_materno
    ].filter(Boolean).join(' ') || 'Cliente sin nombre';
  }

  function agruparClientes(lista) {
    const grupos = new Map();

    lista.forEach(item => {
      const id = String(item.id_cliente);
      if (!grupos.has(id)) {
        grupos.set(id, {
          ...item,
          servicios: []
        });
      }

      if (item.id_servicio !== null && item.id_servicio !== undefined) {
        const grupo = grupos.get(id);
        if (!grupo.servicios.some(s => String(s.id_servicio) === String(item.id_servicio))) {
          grupo.servicios.push({
            id_servicio: item.id_servicio,
            codigo_servicio: item.codigo_servicio,
            estado_servicio: item.estado_servicio
          });
        }
      }
    });

    return Array.from(grupos.values());
  }

  function mostrarResultados(lista) {
    resultados.replaceChildren();

    const clientes = agruparClientes(lista);
    resultados.hidden = clientes.length === 0;

    clientes.forEach(item => {
      const nombre = nombreCompleto(item);
      const boton = document.createElement('button');
      boton.type = 'button';
      boton.className = 'registro-ui__resultado';

      const info = document.createElement('span');
      info.className = 'registro-ui__resultado-info';

      info.appendChild(elemento(
        'strong',
        'registro-ui__resultado-nombre',
        nombre
      ));

      info.appendChild(elemento(
        'span',
        'registro-ui__resultado-meta',
        'Cliente: ' + texto(item.id_cliente)
      ));

      const telefono = texto(item.telefono);
      if (telefono) {
        info.appendChild(elemento(
          'span',
          'registro-ui__resultado-meta',
          'Teléfono: ' + telefono
        ));
      }

      info.appendChild(elemento(
        'span',
        'registro-ui__resultado-servicio',
        item.servicios.length + ' servicio(s) asociado(s)'
      ));

      boton.append(
        elemento('span', 'registro-ui__resultado-avatar', '♙'),
        info,
        elemento('span', 'registro-ui__resultado-flecha', '›')
      );

      boton.addEventListener('click', () => seleccionarCliente(item, nombre));
      resultados.appendChild(boton);
    });
  }

  function seleccionarCliente(item, nombre) {
    campoCliente.value = item.id_cliente || '';
    campoServicio.value = '';
    limpiarReferenciaUbb();
    limpiarObligaciones();

    document.getElementById('clienteSeleccionadoNombre').textContent = nombre;
    document.getElementById('clienteSeleccionadoId').textContent =
      'ID del cliente: ' + texto(item.id_cliente);

    document.getElementById('clienteSeleccionadoTelefono').textContent =
      'Teléfono: ' + texto(item.telefono, 'No disponible');

    document.getElementById('clienteSeleccionadoServicio').textContent =
      'Selecciona el servicio que corresponde al pago';

    document.getElementById('clienteSeleccionadoEstado').textContent = '';

    clienteVacio.hidden = true;
    clienteSeleccionado.hidden = false;
    buscador.value = nombre;
    estado.textContent = 'Selecciona un servicio';

    resultados.replaceChildren();
    resultados.hidden = true;

    if (!serviciosCliente) {
      serviciosCliente = document.createElement('div');
      serviciosCliente.className = 'registro-ui__selector-servicios';
      const datos = clienteSeleccionado.querySelector('.registro-ui__seleccionado-datos');
      datos.insertAdjacentElement('afterend', serviciosCliente);
    }

    serviciosCliente.replaceChildren();
    serviciosCliente.appendChild(elemento(
      'strong',
      'registro-ui__selector-titulo',
      'Servicios de este cliente'
    ));

    if (item.servicios.length === 0) {
      serviciosCliente.appendChild(elemento(
        'p',
        'registro-ui__sin-servicios',
        'Este cliente no tiene servicios asociados.'
      ));

      const boton = document.createElement('button');
      boton.type = 'button';
      boton.className = 'registro-ui__resultado';
      boton.textContent = 'Continuar sin servicio asociado';

      boton.addEventListener('click', () => {
        campoServicio.value = '';
        limpiarReferenciaUbb();
        limpiarObligaciones();
        document.getElementById('clienteSeleccionadoServicio').textContent =
          'Sin servicio asociado';
        document.getElementById('clienteSeleccionadoEstado').textContent =
          'El pago quedará asociado únicamente al cliente.';
        estado.textContent = 'Cliente seleccionado';
        serviciosCliente.replaceChildren();
      });

      serviciosCliente.appendChild(boton);
      return;
    }

    item.servicios.forEach(servicio => {
      const boton = document.createElement('button');
      boton.type = 'button';
      boton.className = 'registro-ui__resultado';

      const codigo = texto(
        servicio.codigo_servicio,
        'Servicio #' + servicio.id_servicio
      );

      boton.appendChild(elemento(
        'strong',
        'registro-ui__resultado-nombre',
        codigo
      ));

      boton.appendChild(elemento(
        'span',
        'registro-ui__resultado-meta',
        'ID del servicio: ' + servicio.id_servicio
      ));

      boton.appendChild(elemento(
        'span',
        'registro-ui__resultado-servicio',
        'Estado: ' + texto(servicio.estado_servicio, 'No disponible')
      ));

      boton.addEventListener('click', () => {
        campoServicio.value = servicio.id_servicio;
        consultarReferenciaUbb(item.id_cliente, servicio.id_servicio);
        consultarObligaciones(item.id_cliente, servicio.id_servicio);

        document.getElementById('clienteSeleccionadoServicio').textContent =
          codigo + ' · ID ' + servicio.id_servicio;

        document.getElementById('clienteSeleccionadoEstado').textContent =
          'Estado: ' + texto(servicio.estado_servicio, 'No disponible');

        serviciosCliente.replaceChildren();
        estado.textContent = 'Cliente y servicio seleccionados';
      });

      serviciosCliente.appendChild(boton);
    });

    // Seleccionar automáticamente cuando el cliente tiene un solo servicio.
    if (item.servicios.length === 1) {
      const botonUnico = serviciosCliente.querySelector(
        'button.registro-ui__resultado'
      );
      botonUnico?.click();
    }
  }

  function limpiarSeleccion() {
    solicitud++;
    clearTimeout(temporizador);

    campoCliente.value = '';
    campoServicio.value = '';
    limpiarReferenciaUbb();
    limpiarObligaciones();
    clienteSeleccionado.hidden = true;
    clienteVacio.hidden = false;
    buscador.value = '';
    estado.textContent = 'Busca otro cliente';

    if (serviciosCliente) serviciosCliente.replaceChildren();

    resultados.replaceChildren();
    resultados.hidden = true;
    buscador.focus();
  }

  async function buscar(termino) {
    const actual = ++solicitud;
    estado.textContent = 'Buscando clientes...';
    resultados.replaceChildren();
    resultados.hidden = true;

    try {
      const url = 'registrar.php?buscar_clientes=1&q=' + encodeURIComponent(termino);
      const respuesta = await fetch(url, {
        headers: { Accept: 'application/json' }
      });

      if (!respuesta.ok) throw new Error('Consulta no disponible');

      const datos = await respuesta.json();
      if (actual !== solicitud) return;
      if (!datos.ok) throw new Error('Consulta no disponible');

      const lista = datos.resultados || [];
      mostrarResultados(lista);

      estado.textContent = lista.length
        ? agruparClientes(lista).length + ' cliente(s). Selecciona uno.'
        : 'No se encontraron clientes.';

    } catch (error) {
      if (actual !== solicitud) return;
      estado.textContent = 'No se pudo consultar la base de datos.';
    }
  }

  buscador.addEventListener('input', () => {
    clearTimeout(temporizador);
    solicitud++;

    campoCliente.value = '';
    campoServicio.value = '';
    limpiarReferenciaUbb();
    limpiarObligaciones();
    clienteSeleccionado.hidden = true;
    clienteVacio.hidden = false;

    if (serviciosCliente) serviciosCliente.replaceChildren();

    resultados.replaceChildren();
    resultados.hidden = true;

    const termino = buscador.value.trim();

    if (termino.length < 1) {
      estado.textContent = 'Escribe al menos 1 carácter';
      return;
    }

    temporizador = setTimeout(() => buscar(termino), 350);
  });

  cambiarCliente?.addEventListener('click', limpiarSeleccion);
})();
