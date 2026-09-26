<?php
/**
 * Panel del personal.
 *
 * Hace tres cosas, según lo que llegue en la dirección:
 *
 *     panel.php                  -> la lista de reportes
 *     panel.php?estado=pendiente -> la lista filtrada
 *     panel.php?id=5             -> el detalle de un reporte
 *
 * Y por POST: cambiar el estado de un reporte y volver a intentar clasificarlo.
 */

require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/plantilla.php';

iniciar_sesion();
exigir_sesion();

$pdo = conectar();

// ---------------------------------------------------------------------------
//  Acciones (llegan por POST)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $accion = isset($_POST['accion']) ? $_POST['accion'] : '';

    if ($id > 0 && $accion === 'estado') {
        $estado = isset($_POST['estado']) ? $_POST['estado'] : '';
        $nota   = isset($_POST['nota'])   ? trim($_POST['nota']) : '';

        // Solo se aceptan estados de la lista. Si llega otra cosa, se ignora.
        if (array_key_exists($estado, estados_posibles())) {
            $pdo->prepare('UPDATE quejas SET estado = :estado, nota = :nota WHERE id = :id')
                ->execute(array(':estado' => $estado, ':nota' => $nota, ':id' => $id));

            dejar_aviso('Reporte actualizado.');
        }
    }

    if ($id > 0 && $accion === 'reclasificar') {
        $consulta = $pdo->prepare('SELECT texto FROM quejas WHERE id = :id LIMIT 1');
        $consulta->execute(array(':id' => $id));
        $texto = $consulta->fetchColumn();

        if ($texto !== false) {
            $resultado = clasificar($texto);

            if ($resultado['ok']) {
                $estado = $resultado['fuera_de_alcance'] ? 'pendiente' : 'canalizado';

                $pdo->prepare(
                    'UPDATE quejas
                        SET categoria = :categoria, confianza = :confianza, estado = :estado
                      WHERE id = :id'
                )->execute(array(
                    ':categoria' => $resultado['categoria'],
                    ':confianza' => $resultado['confianza'],
                    ':estado'    => $estado,
                    ':id'        => $id,
                ));

                dejar_aviso('Listo. Categoría: ' . nombre_categoria($resultado['categoria']) . '.');
            } else {
                dejar_aviso('No se pudo clasificar: ' . $resultado['error']);
            }
        }
    }

    // Después de cualquier acción se vuelve al detalle. Así, si el usuario
    // recarga la página, no se repite la acción.
    header('Location: panel.php?id=' . $id);
    exit;
}

$aviso = tomar_aviso();

// ---------------------------------------------------------------------------
//  Detalle de un reporte
// ---------------------------------------------------------------------------
$id_detalle = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id_detalle > 0) {

    $consulta = $pdo->prepare('SELECT * FROM quejas WHERE id = :id LIMIT 1');
    $consulta->execute(array(':id' => $id_detalle));
    $reporte = $consulta->fetch();

    encabezado('Reporte ' . ($reporte ? $reporte['folio'] : ''), true);

    if ($reporte === false) {
        ?>
        <h1>Ese reporte no existe</h1>
        <p><a class="boton-secundario" href="panel.php">Volver a la lista</a></p>
        <?php
        pie();
        exit;
    }

    $vivo = clasificador_vivo();
    ?>

    <p class="migas"><a href="panel.php">&larr; Volver a la lista</a></p>

    <h1><?= e($reporte['folio']) ?></h1>

    <?php aviso($aviso); ?>

    <div class="tarjeta">
        <h2>Lo que reportó el ciudadano</h2>
        <p class="texto-reporte"><?= nl2br(e($reporte['texto'])) ?></p>

        <dl class="datos">
            <dt>Nombre</dt>
            <dd><?= $reporte['nombre'] !== '' ? e($reporte['nombre']) : 'Anónimo' ?></dd>

            <dt>Colonia o calle</dt>
            <dd><?= $reporte['colonia'] !== '' ? e($reporte['colonia']) : 'No dijo' ?></dd>

            <dt>Recibido</dt>
            <dd><?= e(fecha_legible($reporte['creado_en'])) ?></dd>

            <dt>Desde la IP</dt>
            <dd><?= e($reporte['ip']) ?></dd>
        </dl>
    </div>

    <div class="tarjeta">
        <h2>Clasificación</h2>

        <?php if (!empty($reporte['categoria'])): ?>
            <p>
                <strong><?= e(nombre_categoria($reporte['categoria'])) ?></strong>
                &mdash; le toca a <?= e(area_categoria($reporte['categoria'])) ?>
            </p>
            <p class="ayuda">
                Confianza del modelo: <?= number_format($reporte['confianza'] * 100, 1) ?>%
            </p>
        <?php else: ?>
            <p class="vacio">
                Sin clasificar. Puede que el clasificador estuviera apagado cuando
                llegó el reporte.
            </p>
        <?php endif; ?>

        <form method="post" action="panel.php" class="en-linea">
            <input type="hidden" name="id" value="<?= (int) $reporte['id'] ?>">
            <input type="hidden" name="accion" value="reclasificar">
            <button type="submit" class="boton-secundario">
                Volver a intentar clasificar
            </button>
        </form>

        <p class="ayuda">
            Clasificador: <?= $vivo['ok'] ? 'conectado (modelo ' . e($vivo['modelo']) . ')' : 'no responde' ?>
        </p>
    </div>

    <div class="tarjeta">
        <h2>Seguimiento</h2>

        <form method="post" action="panel.php">
            <input type="hidden" name="id" value="<?= (int) $reporte['id'] ?>">
            <input type="hidden" name="accion" value="estado">

            <div class="campo">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <?php foreach (estados_posibles() as $clave => $nombre): ?>
                        <option value="<?= e($clave) ?>"<?= $reporte['estado'] === $clave ? ' selected' : '' ?>>
                            <?= e($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label for="nota">Nota interna</label>
                <textarea id="nota" name="nota" rows="3"
                          placeholder="Lo que se hizo, a quién se turnó, etc."><?= e($reporte['nota']) ?></textarea>
                <p class="ayuda">Esto no lo ve el ciudadano.</p>
            </div>

            <button type="submit" class="boton">Guardar cambios</button>
        </form>
    </div>

    <?php
    pie();
    exit;
}

// ---------------------------------------------------------------------------
//  Lista de reportes
// ---------------------------------------------------------------------------
$filtro = isset($_GET['estado']) ? $_GET['estado'] : '';
$pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;

if ($pagina < 1) {
    $pagina = 1;
}

$condicion  = '';
$parametros = array();

if ($filtro !== '' && array_key_exists($filtro, estados_posibles())) {
    $condicion = 'WHERE estado = :estado';
    $parametros[':estado'] = $filtro;
}

// Cuántos hay en total, para poder paginar.
$cuenta = $pdo->prepare('SELECT COUNT(*) FROM quejas ' . $condicion);
$cuenta->execute($parametros);
$total = (int) $cuenta->fetchColumn();

$paginas = max(1, (int) ceil($total / REPORTES_POR_PAGINA));

// Si piden una página más allá del final, se muestra la última.
if ($pagina > $paginas) {
    $pagina = $paginas;
}

$desde = ($pagina - 1) * REPORTES_POR_PAGINA;

// LIMIT y OFFSET no se pueden pasar como parámetros normales en MySQL, así que
// van pegados en el SQL. Se puede hacer sin miedo porque las dos variables son
// números enteros: ya se convirtieron con (int) y no pueden traer código.
$sql = 'SELECT id, folio, texto, colonia, categoria, estado, creado_en
          FROM quejas ' . $condicion . '
         ORDER BY id DESC
         LIMIT ' . (int) REPORTES_POR_PAGINA . ' OFFSET ' . (int) $desde;

$consulta = $pdo->prepare($sql);
$consulta->execute($parametros);
$reportes = $consulta->fetchAll();

encabezado('Reportes', true);
?>

<h1>Reportes recibidos</h1>

<?php aviso($aviso); ?>

<div class="resumen">
    <span><strong><?= $total ?></strong> <?= $filtro === '' ? 'en total' : 'con este filtro' ?></span>
    <span class="sep">·</span>
    <span>Página <?= $pagina ?> de <?= $paginas ?></span>
</div>

<div class="filtros">
    <a class="<?= $filtro === '' ? 'activo' : '' ?>" href="panel.php">Todos</a>
    <?php foreach (estados_posibles() as $clave => $nombre): ?>
        <a class="<?= $filtro === $clave ? 'activo' : '' ?>"
           href="panel.php?estado=<?= e($clave) ?>"><?= e($nombre) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($reportes)): ?>

    <?php sin_resultados('No hay reportes que mostrar aquí.'); ?>

<?php else: ?>

    <table class="tabla">
        <thead>
            <tr>
                <th>Folio</th>
                <th>Recibido</th>
                <th>Reporte</th>
                <th>Categoría</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reportes as $reporte): ?>
                <tr>
                    <td>
                        <a href="panel.php?id=<?= (int) $reporte['id'] ?>">
                            <?= e($reporte['folio']) ?>
                        </a>
                    </td>
                    <td class="ahora"><?= e(fecha_legible($reporte['creado_en'])) ?></td>
                    <td>
                        <?= e(recortar($reporte['texto'], 90)) ?>
                        <?php if ($reporte['colonia'] !== ''): ?>
                            <br><span class="ayuda"><?= e($reporte['colonia']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($reporte['categoria'])): ?>
                            <?= e(nombre_categoria($reporte['categoria'])) ?>
                        <?php else: ?>
                            <span class="ayuda">Sin clasificar</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="etiqueta"><?= e(etiqueta_estado($reporte['estado'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($paginas > 1): ?>
        <div class="paginacion">
            <?php if ($pagina > 1): ?>
                <a href="panel.php?pagina=<?= $pagina - 1 ?><?= $filtro !== '' ? '&estado=' . e($filtro) : '' ?>">
                    &larr; Anterior
                </a>
            <?php endif; ?>

            <?php if ($pagina < $paginas): ?>
                <a href="panel.php?pagina=<?= $pagina + 1 ?><?= $filtro !== '' ? '&estado=' . e($filtro) : '' ?>">
                    Siguiente &rarr;
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php pie(); ?>
