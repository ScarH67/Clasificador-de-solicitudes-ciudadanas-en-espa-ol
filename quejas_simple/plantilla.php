<?php
/**
 * El encabezado y el pie de todas las páginas.
 *
 * Están aquí, en funciones, para no tener que copiar el mismo HTML en cada
 * archivo. Si algún día hay que agregar algo al menú, se cambia una sola vez.
 */

/**
 * Imprime el principio de la página.
 *
 * $titulo    lo que sale en la pestaña del navegador
 * $en_panel  true cuando la página es del panel, para que el menú cambie
 */
function encabezado($titulo, $en_panel = false)
{
    $usuario = $en_panel ? usuario_actual() : null;

    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> — <?= e(SITIO_NOMBRE) ?></title>
<link rel="stylesheet" href="estilos.css">
</head>
<body>

<header class="cabecera">
    <div class="contenedor cabecera-fila">
        <a class="marca" href="index.php">
            <?= e(SITIO_NOMBRE) ?>
            <span class="marca-leyenda"><?= e(SITIO_LEYENDA) ?></span>
        </a>

        <nav class="menu">
            <?php if ($en_panel): ?>
                <a href="panel.php">Reportes</a>
                <span class="menu-usuario"><?= e($usuario['nombre']) ?></span>
                <a href="salir.php">Salir</a>
            <?php else: ?>
                <a href="index.php">Reportar</a>
                <a href="consulta.php">Consultar folio</a>
                <a href="entrar.php">Personal</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="contenedor">
<?php
}

/** Imprime el final de la página. */
function pie()
{
    ?>
</main>

<footer class="pie">
    <div class="contenedor">
        <?= e(SITIO_AVISO) ?>
        <br>
        Este sitio no es un servicio del gobierno ni un trámite oficial. Lo que
        se manda aquí se usa únicamente con fines académicos.
    </div>
</footer>

</body>
</html>
<?php
}

/**
 * Muestra un mensaje de aviso.
 *
 * $tipo puede ser 'ok' (verde) o 'error' (rojo).
 */
function aviso($mensaje, $tipo = 'ok')
{
    if (empty($mensaje)) {
        return;
    }

    $clase = $tipo === 'error' ? 'aviso-error' : 'aviso-ok';

    echo '<div class="aviso ' . $clase . '">' . e($mensaje) . '</div>';
}

/**
 * El cuadro que se ve en el panel cuando no hay nada que mostrar.
 */
function sin_resultados($mensaje)
{
    echo '<p class="vacio">' . e($mensaje) . '</p>';
}
