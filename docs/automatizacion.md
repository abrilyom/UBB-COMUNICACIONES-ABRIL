# Automatización de pagos y servicios

Ciclo diario completo, sin intervención manual:

```
Movimientos BBVA (CSV hoy, API después)
        │  importar_movimientos_bancarios.php
        ▼
movimientos_bancarios (PENDIENTE)
        │  conciliar_automatico.php
        ├── referencia válida e importe correcto ──► pago PAGADO ──► REACTIVAR (Grezz) + aviso "pago recibido"
        └── dudas (sin referencia, importe mayor, duplicado…) ──► REVISION (motivo en conciliacion_revisiones)

obligaciones_pago vencidas más de 10 días
        │  suspender_vencidos.php
        ▼
SUSPENDER (Grezz) + aviso "suspensión"

generar_recordatorios.php ──► notificaciones_cola ──► enviar_notificaciones.php (WhatsApp / SMS)
```

## Reglas

| Regla | Valor |
|---|---|
| Periodo de gracia | 10 días después del vencimiento (`UBB_DIAS_GRACIA`) |
| Corte | Día 11 después del vencimiento |
| Recordatorios | 3 días antes, el día del vencimiento y el día 10 (último aviso) |
| Referencia mensual | `UBBC<cliente>S<servicio>M<AAAAMMDD>` → mensualidad exacta |
| Referencia permanente | `UBBC<cliente>S<servicio>` → mensualidad pendiente más antigua |
| Método | Con clave de rastreo = TRANSFERENCIA; sin clave = DEPÓSITO |
| Pagos parciales | Se aplican; la mensualidad sigue PENDIENTE hasta cubrir el saldo |
| Importe mayor al saldo | A revisión manual |
| Reactivación | Solo si el servicio ya no tiene mensualidades fuera de gracia |
| Suspensión | No se envía si el servicio ya está suspendido, si hay una solicitud pendiente o si hay un abono sin conciliar con su referencia |

## Instalación

1. Crear las tablas nuevas (primero en pruebas):

   ```bash
   mysql -u USUARIO -p ubb_pagos_pruebas < db/migraciones/2026-11-25_automatizacion.sql
   ```

2. Probar en simulación (no guarda nada):

   ```bash
   cd /var/www/html/pagos/scripts
   UBB_PAGOS_ENTORNO=pruebas php conciliar_automatico.php
   UBB_PAGOS_ENTORNO=pruebas php suspender_vencidos.php
   UBB_PAGOS_ENTORNO=pruebas php generar_recordatorios.php
   UBB_PAGOS_ENTORNO=pruebas php enviar_notificaciones.php
   ```

3. Cuando los resultados sean correctos, agregar `--confirmar` (o `--enviar` en notificaciones).

## Tareas programadas (cron)

Ejemplo para `crontab -e` dentro del contenedor o servidor donde corre PHP:

```cron
# Conciliar pagos cada 30 minutos (7:00 a 22:00)
*/30 7-22 * * *  cd /var/www/html/pagos/scripts && php conciliar_automatico.php --confirmar >> /var/log/ubb/conciliacion.log 2>&1

# Reintentar solicitudes que Grezz no confirmó
*/10 * * * *     cd /var/www/html/pagos/api && php recuperar_pendientes.php >> /var/log/ubb/grezz.log 2>&1

# Recordatorios diarios a las 9:00 y envío a las 9:05
0 9 * * *        cd /var/www/html/pagos/scripts && php generar_recordatorios.php --confirmar >> /var/log/ubb/notificaciones.log 2>&1
5 9 * * *        cd /var/www/html/pagos/scripts && php enviar_notificaciones.php --enviar >> /var/log/ubb/notificaciones.log 2>&1

# Suspensiones diarias a las 10:00, después de conciliar
0 10 * * *       cd /var/www/html/pagos/scripts && (php conciliar_automatico.php --confirmar && UBB_AUTOMATIZACION_DESDE=2026-12-01 php suspender_vencidos.php --confirmar) >> /var/log/ubb/suspension.log 2>&1
```

### Frenos de seguridad de la suspensión

- `UBB_AUTOMATIZACION_DESDE` (obligatoria para confirmar): solo se consideran las mensualidades que vencen desde esa fecha, para no cortar por adeudos históricos cobrados fuera del sistema.
- `UBB_MAX_SUSPENSIONES` (20 por defecto): si en un día hay más servicios por suspender, no se envía nada hasta que una persona lo revise y ejecute con `--forzar`.
- Si Grezz no responde, no se suspende a nadie.

## Notificaciones

La configuración vive en `/etc/ubb-notificaciones.env` (fuera del repositorio). Ver el encabezado de `pagos/scripts/enviar_notificaciones.php`.

- **WhatsApp Cloud API (Meta):** requiere una cuenta de WhatsApp Business verificada y plantillas aprobadas por Meta, porque los mensajes que inicia la empresa solo pueden enviarse con plantilla.
- **SMS (Twilio):** se activa en minutos con una cuenta y número; tiene costo por mensaje.
- **Sin proveedor:** los mensajes quedan en la cola y el personal los envía desde el módulo Notificaciones.

## Pagos registrados en UISP (mientras llega la API de BBVA)

`pagos/scripts/sincronizar_pagos_uisp.php` lee los pagos que el personal captura en el CRM de UISP y los guarda en `uisp_pagos_importacion`, la misma tabla del CSV del 15/10/2026. Así el historial, el panel y los reportes se actualizan solos.

- **Solo lectura sobre UISP**: usa una llave de aplicación de tipo *Lectura*; no cambia nada en el CRM.
- **Sin duplicados**: cada pago guarda su `uisp_payment_id` (índice único). Si el pago ya venía en el CSV (misma fecha/hora, cliente y monto), solo se le asigna el ID a esa fila.
- Guarda además las facturas que cubrió el pago (`facturas_uisp_json`) y el saldo a favor (`credito_uisp`).
- Normaliza el método igual que el CSV: Efectivo, Transferencia bancaria, Depósito y Tarjeta Débito/Crédito; los demás (cortesía, personalizado, cheque…) quedan como `REVISAR`.
- Limitación: si un pago se **borra o edita** en UISP después de sincronizarse, aquí no se refleja.

### Instalación

1. Aplicar la migración (primero en pruebas):

   ```bash
   mariadb ubb_pagos < db/migraciones/2026-11-27_pagos_uisp_api.sql
   ```

2. Guardar la llave del CRM (Sistema → Seguridad → Claves de aplicación, tipo *Lectura*) fuera del repositorio:

   ```bash
   sudo install -m 600 /dev/null /etc/ubb-uisp.token && sudo nano /etc/ubb-uisp.token
   ```

   El archivo debe poder leerlo el usuario que ejecuta PHP.

3. Simular y, si el resumen es correcto, confirmar la carga desde el día del CSV:

   ```bash
   cd /var/www/html/pagos/scripts
   php sincronizar_pagos_uisp.php --desde=2026-10-15
   php sincronizar_pagos_uisp.php --desde=2026-10-15 --confirmar
   ```

4. Programarlo en el crontab de root de **dns-server** (PHP vive en el contenedor `servidor-aviso-yomyom`; revisa los últimos 7 días cada 15 minutos y es seguro repetirlo):

   ```cron
   */15 * * * *  docker exec servidor-aviso-yomyom php /var/www/html/pagos/scripts/sincronizar_pagos_uisp.php --confirmar >> /var/log/ubb/pagos_uisp.log 2>&1
   ```

Variables opcionales: `UBB_UISP_URL`, `UBB_UISP_TOKEN_FILE`, `UBB_UISP_CA` (certificado del CRM para verificar TLS; sin ella no se verifica) y `UBB_UISP_DIAS`.

### Aplicar los pagos de UISP a las mensualidades

Sincronizar solo guarda el historial. Para que los módulos (pagos de hoy, próximos, vencidos, suspensiones) los reflejen, `pagos/scripts/aplicar_pagos_uisp.php` registra cada pago en `pagos` y lo aplica a `obligaciones_pago`, igual que la conciliación bancaria:

| Caso | Resultado |
|---|---|
| Cliente UISP ligado a un solo cliente UBB (`clientes.uisp_id`) con adeudo en un solo servicio | Pago `PAGADO` (referencia `UISP-<id>`) aplicado de la mensualidad más antigua a la más nueva; la que llega a 0 queda `PAGADA` |
| Pago parcial | Se aplica; la mensualidad sigue pendiente |
| Pago mayor a todo el adeudo, varios servicios con adeudo, cliente sin ligar o sin mensualidades pendientes | `REVISION` |
| Cortesía, personalizado o cheque | `REVISION` (no es un ingreso de dinero) |
| Pago anterior a la primera mensualidad del cliente en UBB (más de 20 días antes de su vencimiento) | `HISTORICO`: se guarda, no se aplica ni se revisa |
| Pago más de 20 días antes del vencimiento de la mensualidad pendiente más antigua | `REVISION` (puede ser de otro periodo) |

Los 20 días (`UBB_UISP_DIAS_ANTICIPO`) son un mes menos los 10 de gracia: un pago que llega antes de eso suele ser del periodo anterior, pagado con retraso.
| El servicio tiene un pago `PENDIENTE` en UBB o un pago igual `PAGADO` en ±3 días | `REVISION` (evita contar el mismo dinero dos veces) |

El resultado queda en `uisp_pagos_importacion.estado_aplicacion` (`APLICADO` / `REVISION` con `motivo_revision`). El personal los revisa en **Historial UISP** (`reporte_uisp.php`, filtro "Aplicación a mensualidades"). No llama a Grezz ni suspende o reactiva servicios.

```bash
mariadb ubb_pagos < db/migraciones/2026-11-28_aplicar_pagos_uisp.sql
mariadb ubb_pagos < db/migraciones/2026-11-29_pagos_uisp_historico.sql
docker exec servidor-aviso-yomyom php /var/www/html/pagos/scripts/aplicar_pagos_uisp.php              # simulación
docker exec servidor-aviso-yomyom php /var/www/html/pagos/scripts/aplicar_pagos_uisp.php --confirmar
```

En el cron, después de sincronizar:

```cron
*/15 * * * *  (docker exec servidor-aviso-yomyom php /var/www/html/pagos/scripts/sincronizar_pagos_uisp.php --confirmar && docker exec servidor-aviso-yomyom php /var/www/html/pagos/scripts/aplicar_pagos_uisp.php --confirmar) >> /var/log/ubb/pagos_uisp.log 2>&1
```

**Deshacer:** `deshacer_pagos_uisp.php` revierte la aplicación completa en una sola transacción: borra los pagos `UISP-…` y sus aplicaciones, regresa a `PENDIENTE` las mensualidades que ya no quedan cubiertas y limpia `estado_aplicacion`. No toca pagos manuales ni bancarios, ni el historial traído de UISP. Guarda un respaldo JSON en `pagos/reportes/`. Simula por defecto; `--confirmar` para deshacer.

**Cuando llegue la API de BBVA:** el mismo dinero aparecería en UISP y en el banco. Antes de activar ambas fuentes hay que decidir cuál aplica los pagos, para no registrarlos dos veces.

### Red: cómo llega dns-server al CRM

Hay **dos redes distintas numeradas 192.168.102.0/24**: la de dns-server (`192.168.102.222`, router `192.168.102.1`) y la del CRM (`ubb-server6`, `192.168.102.253`). Por eso dns-server no puede usar `192.168.102.253`.

Se usa la otra dirección del CRM, **`10.1.41.254`** (interfaz `vlan41`), y en **ubb-server6** hace falta una ruta para que le conteste a dns-server por `vlan41` en lugar de por su red local. Se probó el 25/11/2026 (funcionó) y se retiró: **pendiente de que el administrador de ubb-server6 la autorice**.

```bash
ip route add 192.168.102.222/32 via 10.1.41.1 dev vlan41
```

Autorizada, debe quedar fija en un archivo aparte de netplan (por ejemplo `/etc/netplan/90-ruta-dns-server.yaml`, ruta `192.168.102.222/32 via 10.1.41.1` en `vlan41`). Sin ella, la sincronización falla con *"No se pudo conectar con UISP"* y el panel muestra el error; ningún pago se pierde, se cargan en la siguiente ejecución que funcione (últimos 7 días, o con `--desde`).

## Alcance actual de Grezz

La API de Grezz (RADIUS) por ahora solo expone los **servicios DHCP con equipo relacionado** (344 en la revisión del 25/11/2026: 302 activos, 9 suspendidos y 33 que no pudo resolver, con un campo `error` como `SIN_RELACION`). Los servicios **PPPoE**, que son la mayoría de fibra, todavía no aparecen.

### Verificación de identidad del servicio

`diagnostico_ids_grezz.php` (solo lectura) confirmó el 25/11/2026 que el `id_servicio` de Grezz es el mismo que el de la plataforma: **309 de 311** servicios comparables tienen la misma IP/MAC en Grezz y en `integracion_winbox`. Aun así, antes de cada suspensión el sistema vuelve a comparar la IP/MAC; si no coinciden o no hay datos, el servicio no se corta y queda para revisión manual.

Hallazgo para UBB: la mayoría de los servicios tiene un perfil técnico de velocidad distinto al paquete contratado (por ejemplo, paquete de 25 Mbps con perfil `20M`). No afecta los pagos, pero conviene revisarlo.

Por eso la suspensión automática **solo actúa sobre servicios que Grezz reporta como `ACTIVO`**. Los demás se listan como "no gestionables" para corte manual. Cuando Grezz exponga PPPoE, se incluirán sin cambiar el código de UBB Pagos.

## Pendiente

- **Grezz en modo real:** `cliente_grezz.php` envía `simular: true`. Para operar en real hay que acordar con la plataforma de Grezz qué valor devuelve `resultado` cuando ejecuta la acción, y aceptarlo en `procesador_grezz.php`.
- **API de BBVA:** cuando esté disponible, sus movimientos se guardan en `movimientos_bancarios` con `fuente_datos = 'API'` y siguen exactamente el mismo proceso.
