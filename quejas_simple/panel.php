<?php
/**
 * Panel del personal.
 *
 * Hace tres cosas, según lo que llegue en la dirección:
 *
 *     panel.php                        -> la lista de reportes
 *     panel.php?estado=pendiente       -> la lista filtrada por estado
 *     panel.php?revision=requiere_revision -> los que necesitan revisión
 *     panel.php?id=5                   -> el detalle de un reporte
 *
 * Y por POST:
 *     accion=reclasificar  -> le vuelve a preguntar al modelo
 *     accion=manual        -> guarda la categoría que eligió una persona
 *     accion=estado        -> cambia el estado del trámite y la nota interna
 *
 * Sobre los dos botones de clasificación:
 *
 *   "Reclasificar" manda otra vez el texto al modelo. Útil cuando el
 *   clasificador estaba apagado o cuando se cambió de modelo.
 *
 *   "Clasificar a mano" es para cuando una persona decide la categoría. Deja el
 *   reporte marcado con clasificado_manual = 1, para poder distinguir después
 *   lo que decidió el modelo de lo que decidió alguien del personal.
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

    // Desde la lista conviene volver a la lista (para seguir con el siguiente
    // reporte). Desde el detalle, al detalle. El formulario de la lista manda
    // volver=lista; los demás no lo mandan.
    $destino = 'panel.php?id=' . $id;
    if (isset($_POST['volver']) && $_POST['volver'] === 'lista') {
        $destino = 'panel.php';
    }

    // --- Cambiar el estado del trámite y la nota ---------------------------
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

    // --- Volver a preguntarle al modelo ------------------------------------
    if ($id > 0 && $accion === 'reclasificar') {
        $consulta = $pdo->prepare('SELECT texto FROM quejas WHERE id = :id LIMIT 1');
        $consulta->execute(array(':id' => $id));
        $texto = $consulta->fetchColumn();

        if ($texto !== false) {
            $resultado = clasificar($texto);

            if ($resultado['ok']) {
                guardar_clasificacion($id, $resultado);

                dejar_aviso('Listo. Categoría: ' . nombre_categoria($resultado['categoria'])
                            . ' (' . number_format($resultado['confianza'] * 100, 1) . '%).');
            } else {
                dejar_aviso('No se pudo clasificar: ' . $resultado['error']);
            }
        }
    }

    // --- Clasificar a mano --------------------------------------------------
    if ($id > 0 && $accion === 'manual') {
        $categoria = isset($_POST['categoria']) ? $_POST['categoria'] : '';

        if (guardar_clasificacion_manual($id, $categoria)) {
            dejar_aviso('Reporte clasificado a mano como: ' . nombre_categoria($categoria) . '.');
        } else {
            dejar_aviso('Esa categoría no existe. No se cambió nada.');
        }
    }

    // Después de cualquier acción se vuelve a una página. Así, si el usuario
    // recarga, no se repite la acción.
    header('Location: ' . $destino);
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

    // Los metadatos se guardan como texto JSON. Para mostrarlos se vuelven a
    // armar con sangría, que se leen mucho mejor.
    $metadatos = '';
    if (!empty($reporte['metadatos'])) {
        $crudo = json_decode($reporte['metadatos'], true);
        $metadatos = ($crudo === null)
            ? $reporte['metadatos']
            : json_encode($crudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    ?>

    <p class="migas"><a href="panel.php">&larr; Volver a la lista</a></p>

    <h1><?= e($reporte['folio']) ?></h1>

    <?php aviso($aviso); ?>

    <div class="tarjeta">
        <h2>Lo que reportó el ciudadano</h2>
        <p class="texto-reporte"><?= nl2br(e($reporte['texto'])) ?></p>

        <dl class="datos">
            <dt>Colonia o calle</dt>
            <dd><?= $reporte['colonia'] !== '' ? e($reporte['colonia']) : 'No dijo' ?></dd>

            <dt>Recibido</dt>
            <dd><?= e(fecha_legible($reporte['creado_en'])) ?></dd>

            <dt>Desde la IP</dt>
            <dd><?= e($reporte['ip']) ?></dd>
        </dl>

        <p class="ayuda">
            El formulario ya no pide el nombre: por confidencialidad los reportes
            se guardan sin identificar a nadie. Los que son de antes de ese
            cambio todavía lo tienen, pero aquí ya no se muestra.
        </p>
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
                <?php if ($reporte['tiempo_ms'] !== null): ?>
                    &middot; tardó <?= number_format($reporte['tiempo_ms'], 1) ?> ms
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="vacio">
                Sin clasificar. Puede que el clasificador estuviera apagado cuando
                llegó el reporte.
            </p>
        <?php endif; ?>

        <p>
            <span class="etiqueta <?= $reporte['estado_revision'] === 'requiere_revision' ? 'etiqueta-rev' : 'etiqueta-auto' ?>">
                <?= e(etiqueta_revision($reporte['estado_revision'])) ?>
            </span>
            <?php if ((int) $reporte['clasificado_manual'] === 1): ?>
                <span class="etiqueta etiqueta-mano">Categoría elegida a mano</span>
            <?php endif; ?>
            <?php if ((int) $reporte['posible_fuera_de_alcance'] === 1): ?>
                <span class="etiqueta etiqueta-rev">Fuera de alcance</span>
            <?php endif; ?>
        </p>

        <form method="post" action="panel.php" class="en-linea">
            <input type="hidden" name="id" value="<?= (int) $reporte['id'] ?>">
            <input type="hidden" name="accion" value="reclasificar">
            <button type="submit" class="boton">
                Reclasificar con el modelo
            </button>
        </form>

        <p class="ayuda">
            Clasificador: <?= $vivo['ok'] ? 'conectado (modelo ' . e($vivo['modelo']) . ')' : 'no responde' ?>
        </p>
    </div>

    <div class="tarjeta" id="manual">
        <h2>Clasificar a mano</h2>

        <p class="ayuda">
            Úsalo cuando el modelo no haya acertado o cuando no estuviera
            disponible. Al guardar, el reporte queda marcado como clasificado a
            mano y deja de aparecer en "requiere revisión".
        </p>

        <form method="post" action="panel.php">
            <input type="hidden" name="id" value="<?= (int) $reporte['id'] ?>">
            <input type="hidden" name="accion" value="manual">

            <div class="campo">
                <label for="categoria">Categoría</label>
                <select id="categoria" name="categoria" required>
                    <option value="">— Elegir una —</option>
                    <?php foreach (categorias_manuales() as $clave => $nombre): ?>
                        <option value="<?= e($clave) ?>"<?= $reporte['categoria'] === $clave ? ' selected' : '' ?>>
                            <?= e($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="boton">Guardar clasificación</button>
        </form>
    </div>

    <div class="tarjeta">
        <h2>Datos del ticket</h2>

        <dl class="datos">
            <dt>Folio del API</dt>
            <dd><?= !empty($reporte['folio_api']) ? e($reporte['folio_api']) : 'No se guardó' ?></dd>

            <dt>Fuera de alcance</dt>
            <dd><?= (int) $reporte['posible_fuera_de_alcance'] === 1
                    ? 'Sí, lo marcó el guardián'
                    : 'No' ?></dd>

            <dt>Tiempo del modelo</dt>
            <dd><?= $reporte['tiempo_ms'] !== null
                    ? number_format($reporte['tiempo_ms'], 1) . ' ms'
                    : '—' ?></dd>

            <dt>Clasificado a mano</dt>
            <dd><?= (int) $reporte['clasificado_manual'] === 1 ? 'Sí' : 'No' ?></dd>
        </dl>

        <?php if ($metadatos !== ''): ?>
            <p class="ayuda">Metadatos que devolvió el clasificador:</p>
            <pre class="json"><?= e($metadatos) ?></pre>
        <?php endif; ?>
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
                <p class="ayuda">
                    Esto es el avance del trámite, no la calidad de la
                    clasificación. Son dos cosas distintas.
                </p>
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
$filtro     = isset($_GET['estado'])   ? $_GET['estado']   : '';
$filtro_rev = isset($_GET['revision']) ? $_GET['revision'] : '';
$pagina     = isset($_GET['pagina'])   ? (int) $_GET['pagina'] : 1;

if ($pagina < 1) {
    $pagina = 1;
}

$condiciones = array();
$parametros  = array();

if ($filtro !== '' && array_key_exists($filtro, estados_posibles())) {
    $condiciones[] = 'estado = :estado';
    $parametros[':estado'] = $filtro;
}

if ($filtro_rev !== '' && array_key_exists($filtro_rev, estados_revision())) {
    $condiciones[] = 'estado_revision = :revision';
    $parametros[':revision'] = $filtro_rev;
}

$condicion = empty($condiciones) ? '' : 'WHERE ' . implode(' AND ', $condiciones);

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

// Cuántos están esperando revisión. Es un número fijo, sin nada del usuario
// dentro, así que se puede pedir directo.
$por_revisar = (int) $pdo->query(
    "SELECT COUNT(*) FROM quejas WHERE estado_revision = 'requiere_revision'"
)->fetchColumn();

// LIMIT y OFFSET no se pueden pasar como parámetros normales en MySQL, así que
// van pegados en el SQL. Se puede hacer sin miedo porque las dos variables son
// números enteros: ya se convirtieron con (int) y no pueden traer código.
$sql = 'SELECT id, folio, texto, colonia, categoria, confianza, estado,
               estado_revision, clasificado_manual, creado_en
          FROM quejas ' . $condicion . '
         ORDER BY id DESC
         LIMIT ' . (int) REPORTES_POR_PAGINA . ' OFFSET ' . (int) $desde;

$consulta = $pdo->prepare($sql);
$consulta->execute($parametros);
$reportes = $consulta->fetchAll();

// Para que los enlaces de paginación no pierdan el filtro que esté puesto.
$cola = '';
if ($filtro !== '') {
    $cola .= '&estado=' . e($filtro);
}
if ($filtro_rev !== '') {
    $cola .= '&revision=' . e($filtro_rev);
}

encabezado('Reportes', true);
?>

<h1>Reportes recibidos</h1>

<?php aviso($aviso); ?>

<div class="resumen">
    <span><strong><?= $total ?></strong> <?= ($filtro === '' && $filtro_rev === '') ? 'en total' : 'con este filtro' ?></span>
    <span class="sep">·</span>
    <span>Página <?= $pagina ?> de <?= $paginas ?></span>
</div>

<div class="filtros">
    <a class="<?= ($filtro === '' && $filtro_rev === '') ? 'activo' : '' ?>" href="panel.php">Todos</a>
    <?php foreach (estados_posibles() as $clave => $nombre): ?>
        <a class="<?= $filtro === $clave ? 'activo' : '' ?>"
           href="panel.php?estado=<?= e($clave) ?>"><?= e($nombre) ?></a>
    <?php endforeach; ?>
</div>

<div class="filtros">
    <a class="filtro-rev <?= $filtro_rev === 'requiere_revision' ? 'activo' : '' ?>"
       href="panel.php?revision=requiere_revision">
        Requiere revisión<?= $por_revisar > 0 ? ' (' . $por_revisar . ')' : '' ?>
    </a>
    <a class="filtro-rev <?= $filtro_rev === 'revisado_manual' ? 'activo' : '' ?>"
       href="panel.php?revision=revisado_manual">Clasificados a mano</a>
    <a class="filtro-rev <?= $filtro_rev === 'sin_clasificar' ? 'activo' : '' ?>"
       href="panel.php?revision=sin_clasificar">Sin clasificar</a>
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
                <th>Acciones</th>
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

                        <br>
                        <span class="etiqueta <?= $reporte['estado_revision'] === 'requiere_revision' ? 'etiqueta-rev' : 'etiqueta-auto' ?>">
                            <?= e(etiqueta_revision($reporte['estado_revision'])) ?>
                        </span>
                        <?php if ((int) $reporte['clasificado_manual'] === 1): ?>
                            <span class="etiqueta etiqueta-mano">A mano</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="etiqueta"><?= e(etiqueta_estado($reporte['estado'])) ?></span></td>
                    <td class="celda-acciones">
                        <div class="acciones-fila">
                            <form method="post" action="panel.php">
                                <input type="hidden" name="id" value="<?= (int) $reporte['id'] ?>">
                                <input type="hidden" name="accion" value="reclasificar">
                                <input type="hidden" name="volver" value="lista">
                                <button type="submit" class="boton-mini"
                                        title="Volver a preguntarle al modelo">Reclasificar</button>
                            </form>
                            <a class="boton-mini"
                               href="panel.php?id=<?= (int) $reporte['id'] ?>#manual">Clasificar a mano</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($paginas > 1): ?>
        <div class="paginacion">
            <?php if ($pagina > 1): ?>
                <a href="panel.php?pagina=<?= $pagina - 1 ?><?= $cola ?>">
                    &larr; Anterior
                </a>
            <?php endif; ?>

            <?php if ($pagina < $paginas): ?>
                <a href="panel.php?pagina=<?= $pagina + 1 ?><?= $cola ?>">
                    Siguiente &rarr;
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php pie(); ?>
