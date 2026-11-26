// Campanita del encabezado: avisos que la plataforma deja para Pagos.
// Habla solo con avisos_plataforma.php (la llave de la API no llega al navegador).
(() => {
  const contenedor = document.getElementById('ubbAvisos');
  const boton = document.getElementById('ubbAvisosBoton');
  const contador = document.getElementById('ubbAvisosContador');
  const panel = document.getElementById('ubbAvisosPanel');
  const estado = document.getElementById('ubbAvisosEstado');
  const lista = document.getElementById('ubbAvisosLista');
  const refrescar = document.getElementById('ubbAvisosRefrescar');

  if (!contenedor || !boton || !contador || !panel || !estado || !lista || !refrescar) return;

  const ENDPOINT = 'avisos_plataforma.php';
  let avisos = [];

  function mostrarContador() {
    const n = avisos.length;
    contador.hidden = n === 0;
    contador.textContent = n > 99 ? '99+' : String(n);
    boton.setAttribute('aria-label', n === 0
      ? 'Avisos de la plataforma: sin pendientes'
      : `Avisos de la plataforma: ${n} pendiente${n === 1 ? '' : 's'}`);
  }

  function formatearFecha(texto) {
    if (!texto) return '';
    const fecha = new Date(texto.replace(' ', 'T'));
    if (Number.isNaN(fecha.getTime())) return texto;
    return fecha.toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  function enlace(texto, href) {
    const a = document.createElement('a');
    a.href = href;
    a.textContent = texto;
    return a;
  }

  function pintarLista() {
    lista.replaceChildren();

    if (avisos.length === 0) {
      estado.hidden = false;
      estado.textContent = 'No hay avisos pendientes.';
      return;
    }

    estado.hidden = true;

    for (const aviso of avisos) {
      const li = document.createElement('li');
      li.className = 'ubb-aviso';

      const titulo = document.createElement('strong');
      titulo.textContent = aviso.titulo;
      li.append(titulo);

      if (aviso.mensaje && aviso.mensaje !== aviso.titulo) {
        const p = document.createElement('p');
        p.textContent = aviso.mensaje;
        li.append(p);
      }

      const pie = document.createElement('div');
      pie.className = 'ubb-aviso-pie';

      const datos = document.createElement('span');
      datos.textContent = [formatearFecha(aviso.fecha), aviso.cliente, aviso.codigo_servicio]
        .filter(Boolean)
        .join(' · ');
      if (aviso.id_cliente) datos.append(' · ', enlace(`Cliente ${aviso.id_cliente}`, `ver_cliente.php?id=${encodeURIComponent(aviso.id_cliente)}`));
      if (aviso.id_servicio) datos.append(' · ', enlace(`Servicio ${aviso.id_servicio}`, `ver_servicio.php?id=${encodeURIComponent(aviso.id_servicio)}`));
      pie.append(datos);

      const revisar = document.createElement('button');
      revisar.type = 'button';
      revisar.className = 'ubb-aviso-revisar';
      revisar.textContent = 'Marcar revisado';
      revisar.addEventListener('click', () => marcarRevisado(aviso.id, revisar));
      pie.append(revisar);

      li.append(pie);
      lista.append(li);
    }
  }

  async function cargar(forzar = false) {
    estado.hidden = false;
    estado.textContent = 'Cargando…';
    refrescar.disabled = true;

    try {
      const r = await fetch(ENDPOINT + (forzar ? '?refrescar=1' : ''), { headers: { Accept: 'application/json' } });
      const datos = await r.json();

      if (!datos.ok) {
        avisos = [];
        mostrarContador();
        lista.replaceChildren();
        estado.textContent = datos.error || 'No se pudieron consultar los avisos.';
        return;
      }

      avisos = Array.isArray(datos.avisos) ? datos.avisos : [];
      mostrarContador();
      pintarLista();
    } catch (e) {
      lista.replaceChildren();
      estado.textContent = 'No se pudieron consultar los avisos.';
    } finally {
      refrescar.disabled = false;
    }
  }

  async function marcarRevisado(id, control) {
    control.disabled = true;
    control.textContent = 'Guardando…';

    try {
      const r = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' },
        body: new URLSearchParams({ id }),
      });
      const datos = await r.json();

      if (!datos.ok) throw new Error(datos.error || 'Error');

      avisos = avisos.filter((a) => a.id !== id);
      mostrarContador();
      pintarLista();
    } catch (e) {
      control.disabled = false;
      control.textContent = 'Reintentar';
      control.title = 'No se pudo marcar como revisado.';
    }
  }

  // El panel es position:fixed (el encabezado recorta lo que sobresale),
  // así que se coloca debajo de la campanita según su posición en pantalla.
  function colocarPanel() {
    const r = boton.getBoundingClientRect();
    panel.style.top = `${Math.round(r.bottom + 10)}px`;
    panel.style.right = `${Math.max(16, Math.round(window.innerWidth - r.right - 8))}px`;
  }

  function abrir(abierto) {
    if (abierto) colocarPanel();
    panel.hidden = !abierto;
    boton.setAttribute('aria-expanded', String(abierto));
  }

  window.addEventListener('resize', () => { if (!panel.hidden) colocarPanel(); });
  window.addEventListener('scroll', () => { if (!panel.hidden) colocarPanel(); }, { passive: true });

  boton.addEventListener('click', (e) => {
    e.stopPropagation();
    abrir(panel.hidden);
  });

  refrescar.addEventListener('click', () => cargar(true));

  document.addEventListener('click', (e) => {
    if (!panel.hidden && !contenedor.contains(e.target)) abrir(false);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !panel.hidden) {
      abrir(false);
      boton.focus();
    }
  });

  cargar();
})();
