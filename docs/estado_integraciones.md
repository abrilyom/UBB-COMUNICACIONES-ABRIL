# Estado de las integraciones (revisión del 25/11/2026)

## Bases de datos

| Base | Dueño | Servidor | Uso desde UBB Pagos |
|---|---|---|---|
| `ubb_pagos` / `ubb_pagos_pruebas` | UBB Pagos | local (contenedor → MariaDB del host) | Lectura y escritura |
| `ubb_plataforma` | Jamileth | 192.168.100.221 | **Solo lectura**: `clientes`, `servicios`, `direcciones_servicio` |
| `ubb_gestion_planes` | Grezz | 192.168.100.223 | **Solo lectura**: `planes`, `servicios_planes` |

Identificadores compartidos: `id_cliente`, `id_servicio`, `id_plan`. Para UISP: `clientes.uisp_id`.

## Integraciones

| Integración | Estado | Archivos |
|---|---|---|
| Consulta de clientes y servicios (Jamileth) | ✅ Implementada (lectura) | `conexion_plataforma.php`, `clientes.php`, `activos.php`, `ver_cliente.php` |
| Consulta de planes y precios (Grezz) | ✅ Implementada (lectura) | `conexion_planes.php`, `scripts/simular_obligaciones.php` |
| API RADIUS de Grezz: consulta de estado | ✅ Implementada | `api/cliente_grezz.php` (`GET /api-radius/v1/servicios[/{id}]`) |
| API RADIUS de Grezz: suspender / reactivar | 🟡 Implementada **solo en simulación** (`"simular": true`) | `api/cliente_grezz.php`, `api/procesador_grezz.php` |
| API propuesta `/api/v1/solicitudes/estado-servicio` | ⚪ Propuesta, **no implementada por Grezz** | `api/cliente_planes.php` (cliente listo, sin uso) |
| API privada de UBB Pagos (para otras plataformas) | ✅ Implementada | `api/v1/clientes.php`, `servicios.php`, `solicitudes.php` |
| Conciliación bancaria (CSV) | ✅ Implementada y probada | `scripts/importar_movimientos_bancarios.php`, `scripts/conciliar_automatico.php` |
| API de BBVA | ⏳ Pendiente del banco | — usará el mismo proceso de conciliación |
| Pagos registrados en UISP | ✅ Implementada (API del CRM vía NAT en MKR-FSL02, 10.0.1.6:18445); se aplica a mensualidades | `scripts/sincronizar_pagos_uisp.php` → `uisp_pagos_importacion` (ver `docs/automatizacion.md`) |
| Aviso al monitoreo de Christian (Telegram) en cortes y reactivaciones | ✅ Instalado y probado en dns-server (26/11/2026); solo se envía con solicitudes `COMPLETADA`, así que no hay avisos reales mientras Grezz siga en simulación | `api/aviso_monitoreo.php`, `api/procesador_grezz.php` |
| Avisos de la plataforma en la campanita (factura nueva, vencida, corte, cambios de servicio) | ✅ Instalado y probado en dns-server (26/11/2026), API en 192.168.100.221:8081 | `api/cliente_avisos_plataforma.php`, `avisos_plataforma.php`, `avisos-plataforma.js`, `includes/encabezado.php` |
| Notificaciones WhatsApp / SMS | 🟡 Implementada, sin proveedor contratado | `scripts/generar_recordatorios.php`, `scripts/enviar_notificaciones.php` |
| `integracion_winbox` (Jamileth) | Solo consulta | No se modifica |

## Diferencias entre la propuesta y lo implementado

| Tema | Propuesta | Implementado hoy |
|---|---|---|
| Endpoint | `POST /api/v1/solicitudes/estado-servicio` | `POST /api-radius/v1/servicios/{id}/suspender` o `/reactivar` |
| Estados | `PENDIENTE`, `COMPLETADA`, `ERROR` | `PENDIENTE`, `SIMULADA`, `RECHAZADA` |
| PPPoE | Incluido | La API de Grezz solo expone DHCP con equipo relacionado |

Falta que Grezz confirme qué valor de `resultado` devuelve al ejecutar con `"simular": false`, para registrar el estado real (`COMPLETADA`).

## Cumplimiento de las reglas del proyecto

| Regla | Cómo se cumple |
|---|---|
| No cambiar identificadores originales | Ningún proceso modifica `id_cliente`, `id_servicio` ni `id_plan` |
| No modificar `servicios_planes` masivamente | Los procesos automáticos no escriben en `ubb_gestion_planes` |
| No modificar los servicios 1255 y 1299 | `UBB_SERVICIOS_PROTEGIDOS` en `includes/automatizacion_servicios.php` bloquea cualquier suspensión o reactivación automática |
| No alterar bases remotas | Las tablas nuevas (`conciliacion_revisiones`, `notificaciones_cola`) se crean solo en `ubb_pagos` |
| No modificar referencias existentes | La conciliación solo lee `referencia_cobro` |
| Sin operaciones reales en pruebas | Todo simula por defecto; Grezz recibe `"simular": true`; en pruebas los mensajes se desvían a `UBB_TELEFONO_PRUEBAS` |
| Separar estado del pago y estado técnico | El pago se guarda en `pagos`/`obligaciones_pago`; el estado técnico en `solicitudes_estado_servicio` |
| Proteger credenciales y datos personales | Conexiones, tokens (`/etc/ubb-*.env`), respaldos SQL, comprobantes y reportes fuera del repositorio (`.gitignore`) |
