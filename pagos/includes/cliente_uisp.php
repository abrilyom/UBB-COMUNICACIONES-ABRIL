<?php
declare(strict_types=1);

/**
 * Cliente de SOLO LECTURA para la API del CRM de UISP (v1.0).
 *
 * Lo usan sincronizar_pagos_uisp.php y sincronizar_facturas_uisp.php.
 * Solo hace peticiones GET: no crea, edita ni borra nada en el CRM.
 *
 * Configuración (variables de entorno, todas opcionales):
 *   UBB_UISP_URL         https://10.0.1.6:18445/crm/api/v1.0  (regla NAT "Pagos UBB -> UISP" en MKR-FSL02)
 *   UBB_UISP_TOKEN_FILE  /etc/ubb-uisp.token  (llave de aplicación de tipo Lectura)
 *   UBB_UISP_CA          certificado del CRM para verificar TLS
 */

if (!defined('UISP_TAMANO_PAGINA')) {
    define('UISP_TAMANO_PAGINA', 500);
}

final class ClienteUisp
{
    private string $url;
    private string $llave;
    private ?string $ca;

    public function __construct()
    {
        $this->url = rtrim(getenv('UBB_UISP_URL') ?: 'https://10.0.1.6:18445/crm/api/v1.0', '/');
        $archivo = getenv('UBB_UISP_TOKEN_FILE') ?: '/etc/ubb-uisp.token';

        if (!is_readable($archivo)) {
            throw new RuntimeException("Llave de UISP no disponible ($archivo).");
        }

        $this->llave = trim((string)file_get_contents($archivo));

        if ($this->llave === '') {
            throw new RuntimeException('Llave de UISP vacía.');
        }

        $this->ca = getenv('UBB_UISP_CA') ?: null;

        if ($this->ca === null) {
            echo "Aviso: certificado del CRM sin verificar (define UBB_UISP_CA para verificarlo).\n";
        }
    }

    /** GET a la API; devuelve el JSON decodificado. Reintenta 2 veces si UISP no responde. */
    public function get(string $ruta, array $parametros = []): array
    {
        $url = $this->url . $ruta . ($parametros ? '?' . http_build_query($parametros) : '');

        for ($intento = 1; ; $intento++) {
            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'X-Auth-App-Key: ' . $this->llave,
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => $this->ca !== null,
                CURLOPT_SSL_VERIFYHOST => $this->ca !== null ? 2 : 0,
            ]);

            if ($this->ca !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->ca);
            }

            $cuerpo = curl_exec($ch);
            $error = curl_error($ch);
            $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);

            $reintentable = $cuerpo === false || $codigo >= 500 || $codigo === 429;

            if ($reintentable && $intento < 3) {
                sleep(2 * $intento);
                continue;
            }

            if ($cuerpo === false) {
                throw new RuntimeException("No se pudo conectar con UISP: $error");
            }

            if ($codigo === 401 || $codigo === 403) {
                throw new RuntimeException("UISP rechazó la llave (HTTP $codigo).");
            }

            if ($codigo !== 200) {
                throw new RuntimeException("UISP respondió HTTP $codigo en $ruta.");
            }

            $datos = json_decode($cuerpo, true);

            if (!is_array($datos)) {
                throw new RuntimeException("Respuesta inválida de UISP en $ruta.");
            }

            return $datos;
        }
    }

    /** Todos los registros de un endpoint de lista, paginados. */
    public function lista(string $ruta, array $parametros = []): array
    {
        $registros = [];

        for ($offset = 0; ; $offset += UISP_TAMANO_PAGINA) {
            $pagina = $this->get($ruta, $parametros + [
                'limit' => UISP_TAMANO_PAGINA,
                'offset' => $offset,
            ]);

            array_push($registros, ...$pagina);

            if (count($pagina) < UISP_TAMANO_PAGINA) {
                return $registros;
            }
        }
    }

    /** Pagos creados entre dos fechas (inclusive). */
    public function pagos(string $desde, string $hasta): array
    {
        return $this->lista('/payments', [
            'createdDateFrom' => $desde,
            'createdDateTo' => $hasta,
            'order' => 'createdDate',
            'direction' => 'ASC',
        ]);
    }

    /** Facturas creadas entre dos fechas (inclusive). */
    public function facturas(string $desde, string $hasta): array
    {
        return $this->lista('/invoices', [
            'createdDateFrom' => $desde,
            'createdDateTo' => $hasta,
            'order' => 'createdDate',
            'direction' => 'ASC',
        ]);
    }

    /** Catálogo id => nombre de un endpoint de lista. */
    public function catalogo(string $ruta, callable $nombre): array
    {
        $catalogo = [];

        foreach ($this->get($ruta) as $fila) {
            $catalogo[(string)$fila['id']] = $nombre($fila);
        }

        return $catalogo;
    }
}
