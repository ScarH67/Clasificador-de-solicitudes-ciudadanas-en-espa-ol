<?php
/**
 * Consultar un reporte por su folio.
 *
 * Es solo un formulario: al enviarlo manda al ciudadano a reporte.php con el
 * folio en la dirección. Así el enlace queda guardable y compartible, y no hay
 * que duplicar la lógica de mostrar un reporte en dos archivos.
 */

require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/plantilla.php';

$folio = isset($_GET['folio']) ? trim($_GET['folio']) : '';

encabezado('Consultar folio');
?>

<h1>Consultar un reporte</h1>

<p class="entrada">
    Escribe el folio que te dimos cuando enviaste tu reporte. Tiene la forma
    <strong>Mex-2026-000001</strong>.
</p>

<form method="get" action="reporte.php" class="tarjeta">
    <div class="campo">
        <label for="folio">Folio</label>
        <input type="text" id="folio" name="folio" value="<?= e($folio) ?>"
               placeholder="MEX-2026-000001" required autofocus>
    </div>

    <button type="submit" class="boton">Buscar</button>
</form>

<?php pie(); ?>
