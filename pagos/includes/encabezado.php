<?php
// Encabezado compartido de UBB Comunicaciones.

// Usuario que entró desde la plataforma (includes/sesion.php).
$usuarioEncabezado = function_exists('usuarioSesion') ? usuarioSesion() : null;
$nombreEncabezado = $usuarioEncabezado
    ? htmlspecialchars((string)$usuarioEncabezado['nombre'], ENT_QUOTES, 'UTF-8')
    : 'Sin sesión';
$rolEncabezado = $usuarioEncabezado
    ? htmlspecialchars(UBB_ROLES_PLATAFORMA[$usuarioEncabezado['rol']] ?? (string)$usuarioEncabezado['rol'], ENT_QUOTES, 'UTF-8')
    : 'Entra desde la plataforma';
$urlPlataformaEncabezado = function_exists('urlPlataformaNavegador')
    ? htmlspecialchars(urlPlataformaNavegador(), ENT_QUOTES, 'UTF-8')
    : '#';
?>
<header class="encabezado ubb-encabezado-ref">
    <div class="marca">
        <img class="logo-ubb"
             src="../assets/img/logo-ubb.jpg"
             alt="Logo de UBB Comunicaciones"><div class="marca-texto">
            <h1>UBB</h1>
            <div class="marca-subtitulo">COMUNICACIONES</div>
            <div class="marca-separador"></div>
            <p class="marca-modulo">PAGOS</p>
        </div>
        <div class="ubb-sistema-activo">
            <span class="ubb-punto-activo"></span>SISTEMA ACTIVO
        </div>
    </div>

    <div class="barra-superior">

        <div class="ubb-herramientas" aria-label="Herramientas">
            <div class="ubb-avisos" id="ubbAvisos">
                <button type="button" class="ubb-icono ubb-avisos-boton" id="ubbAvisosBoton"
                        title="Avisos de la plataforma" aria-label="Avisos de la plataforma"
                        aria-haspopup="true" aria-expanded="false" aria-controls="ubbAvisosPanel">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><i id="ubbAvisosContador" hidden></i>
                </button>
                <div class="ubb-avisos-panel" id="ubbAvisosPanel" role="region" aria-label="Avisos de la plataforma" hidden>
                    <div class="ubb-avisos-cabecera">
                        <strong>Avisos de la plataforma</strong>
                        <button type="button" class="ubb-avisos-refrescar" id="ubbAvisosRefrescar">Actualizar</button>
                    </div>
                    <p class="ubb-avisos-estado" id="ubbAvisosEstado" aria-live="polite">Cargando…</p>
                    <ul class="ubb-avisos-lista" id="ubbAvisosLista"></ul>
                </div>
            </div>
            <span class="ubb-icono" title="Configuración" aria-label="Configuración">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z"/></svg>
            </span>
            <span class="ubb-icono" title="Ayuda" aria-label="Ayuda">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.6 9a2.5 2.5 0 1 1 4.2 1.8c-1.1 1-1.8 1.4-1.8 3"/><path d="M12 17.5h.01"/></svg>
            </span>
            <span class="ubb-icono" title="Calendario" aria-label="Calendario">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            </span>
        </div>

        <div class="ubb-perfil">
            <span class="ubb-avatar" aria-hidden="true">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                <?php if ($usuarioEncabezado): ?><span class="ubb-avatar-activo"></span><?php endif; ?>
            </span>
            <span class="administrador ubb-usuario-sesion">
                <span class="ubb-usuario-nombre"><?php echo $nombreEncabezado; ?></span>
                <small class="ubb-usuario-rol"><?php echo $rolEncabezado; ?></small>
            </span>
        </div>
        <?php if ($usuarioEncabezado): ?>
        <a class="ubb-salir-visual" href="?salir=1" title="Salir" aria-label="Salir">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M13 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
        </a>
        <?php else: ?>
        <a class="ubb-salir-visual" href="<?php echo $urlPlataformaEncabezado; ?>" title="Entrar desde la plataforma" aria-label="Entrar desde la plataforma">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 17l5-5-5-5M19 12H7"/><path d="M11 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/></svg>
        </a>
        <?php endif; ?>
    </div>
</header>
<script src="avisos-plataforma.js?v=<?php echo filemtime(__DIR__ . '/../avisos-plataforma.js'); ?>" defer></script>
