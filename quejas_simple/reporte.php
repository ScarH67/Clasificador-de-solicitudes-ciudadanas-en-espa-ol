<?php
/**
 * Muestra el resultado de un reporte recién enviado.
 *
 * Se llega aquí después de enviar el formulario, con el folio en la dirección:
 *     reporte.php?folio=CDMX-2026-000001
 *
 * También sirve para que el ciudadano vuelva a ver su reporte si guardó el
 * enlace.
 */

require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/plantilla.php';

$folio = isset($_GET['folio']) ? trim($_GET['folio']) : '';

// Viene de enviar el formulario, no de una consulta. Solo cambia el saludo.
$es_nuevo = !empty($_GET['nuevo']);

$reporte = null;

if ($folio !== '') {
    $consulta = conectar()->prepare('SELECT * FROM quejas WHERE folio = :folio LIMIT 1');
    $consulta->execute(array(':folio' => $folio));
    $reporte = $consulta->fetch();
}

encabezado('Tu reporte');
?>

<?php if ($reporte === false || $reporte === null): ?>

    <h1>No encontré ese folio</h1>
    <p class="entrada">
        Revisa que esté escrito tal cual te lo dimos. Debe verse así:
        <strong>CDMX-2026-000001</strong>.
    </p>
    <p><a class="boton" href="consulta.php">Buscar otro folio</a></p>

<?php else: ?>

    <h1><?= $es_nuevo ? 'Tu reporte quedó registrado' : 'Estado de tu reporte' ?></h1>

    <div class="tarjeta folio-caja">
        <p class="folio-etiqueta"><?= $es_nuevo ? 'Guarda este folio' : 'Folio' ?></p>
        <p class="folio-numero"><?= e($reporte['folio']) ?></p>
        <?php if ($es_nuevo): ?>
            <p class="ayuda">
                Con este número puedes consultar el estado de tu reporte cuando quieras.
            </p>
        <?php endif; ?>
    </div>

    <div class="tarjeta">
        <h2>Qué sigue</h2>

        <?php if (!empty($reporte['categoria'])): ?>
            <p>
                Tu reporte fue clasificado como
                <strong><?= e(nombre_categoria($reporte['categoria'])) ?></strong>,
                que le corresponde a <strong><?= e(area_categoria($reporte['categoria'])) ?></strong>.
            </p>
        <?php else: ?>
            <p>
                Tu reporte quedó guardado, pero el sistema no pudo clasificarlo en
                este momento. Una persona lo va a revisar y lo va a mandar al área
                que le toca.
            </p>
        <?php endif; ?>

        <p>
            Estado actual:
            <span class="etiqueta"><?= e(etiqueta_estado($reporte['estado'])) ?></span>
        </p>
    </div>

    <div class="tarjeta">
        <h2>Lo que escribiste</h2>
        <p class="texto-reporte"><?= nl2br(e($reporte['texto'])) ?></p>
        <p class="ayuda">Enviado el <?= e(fecha_legible($reporte['creado_en'])) ?></p>
    </div>

    <div class="tarjeta">
        <h2>Resumen técnico</h2>

        <dl class="datos datos-tecnicos">
            <dt>Qué tan seguro estuvo el sistema</dt>
            <dd>
                <?php if (!empty($reporte['categoria'])): ?>
                    <?= e(confianza_legible($reporte['confianza'])) ?>
                <?php else: ?>
                    Sin clasificar
                <?php endif; ?>
            </dd>

            <dt>Tiempo de respuesta</dt>
            <dd><?= e(tiempo_legible($reporte['tiempo_ms'])) ?></dd>

            <dt>Modelo usado</dt>
            <dd><?= e(nombre_modelo($reporte['metadatos'])) ?></dd>
        </dl>

        <p class="ayuda">
            Son datos del clasificador automático. Si el modelo no estaba
            disponible en ese momento, el reporte se guarda igual y una persona
            lo clasifica después.
        </p>
    </div>

    <p><a class="boton-secundario" href="index.php">Reportar otro problema</a></p>

<?php endif; ?>

<?php pie(); ?>
