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

## Alcance actual de Grezz

La API de Grezz (RADIUS) por ahora solo expone los **servicios DHCP con equipo relacionado** (344 en la revisión del 25/11/2026: 302 activos, 9 suspendidos y 33 que no pudo resolver, con un campo `error` como `SIN_RELACION`). Los servicios **PPPoE**, que son la mayoría de fibra, todavía no aparecen.

Por eso la suspensión automática **solo actúa sobre servicios que Grezz reporta como `ACTIVO`**. Los demás se listan como "no gestionables" para corte manual. Cuando Grezz exponga PPPoE, se incluirán sin cambiar el código de UBB Pagos.

## Pendiente

- **Grezz en modo real:** `cliente_grezz.php` envía `simular: true`. Para operar en real hay que acordar con la plataforma de Grezz qué valor devuelve `resultado` cuando ejecuta la acción, y aceptarlo en `procesador_grezz.php`.
- **API de BBVA:** cuando esté disponible, sus movimientos se guardan en `movimientos_bancarios` con `fuente_datos = 'API'` y siguen exactamente el mismo proceso.
