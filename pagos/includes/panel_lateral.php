<?php
// Panel lateral compartido. La opción activa depende de la página actual.
$paginaActual = basename($_SERVER["PHP_SELF"] ?? "index.php");

$opcionesMenu = [
    ["archivo" => "index.php", "texto" => "Centro de control",
     "icono" => '<path d="m3 10 9-7 9 7"/><path d="M5 9v12h14V9M9 21v-7h6v7"/>'],
    ["archivo" => "registrar.php", "texto" => "Registrar pago",
     "icono" => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>'],
    ["archivo" => "historial.php", "texto" => "Historial",
     "icono" => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>'],
    ["archivo" => "notificaciones.php", "texto" => "Notificaciones",
     "icono" => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>'],
    ["archivo" => "conciliacion_bancaria.php", "texto" => "Conciliación bancaria",
     "icono" => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M7 15h4"/>'],
    ["archivo" => "revision_uisp.php", "texto" => "Revisión UISP",
     "icono" => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>'],
    ["archivo" => "clientes.php", "texto" => "Clientes",
     "icono" => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 3.2a4 4 0 0 1 0 7.6M20 21v-2a4 4 0 0 0-3-3.9"/>'],
    ["archivo" => "reporte_semanal.php", "texto" => "Reportes",
     "icono" => '<path d="M3 3v18h18"/><rect x="6" y="12" width="3" height="6" rx=".5"/><rect x="11" y="8" width="3" height="10" rx=".5"/><rect x="16" y="5" width="3" height="13" rx=".5"/>']
];
?>
<aside class="menu-lateral-ubb" id="menu-lateral-ubb">
    <nav aria-label="Navegación principal de pagos">
        <?php foreach ($opcionesMenu as $opcion): ?>
            <?php $activo = $paginaActual === $opcion["archivo"]; ?>
            <a href="<?php echo htmlspecialchars($opcion["archivo"], ENT_QUOTES, "UTF-8"); ?>"
               class="<?php echo $activo ? "activo" : ""; ?>"
               <?php echo $activo ? 'aria-current="page"' : ""; ?>>
                <span class="icono-menu">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <?php echo $opcion["icono"]; ?>
                    </svg>
                </span>
                <?php echo htmlspecialchars($opcion["texto"], ENT_QUOTES, "UTF-8"); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="menu-pie-ubb">
        <div class="menu-pie-emblema menu-pie-emblema-texto">
            <img src="../assets/img/fondotransparente-ubb.png"
                 alt="" aria-hidden="true">
            <div class="menu-logo-texto">
                <strong>UBB COMUNICACIONES</strong>
                <span>UNIENDO TU MUNDO</span>
            </div>
        </div>
    </div>
</aside>
