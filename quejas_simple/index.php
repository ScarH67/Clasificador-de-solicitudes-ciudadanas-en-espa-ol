<?php
/**
 * Formulario público. Es la página de entrada.
 *
 * Este archivo hace dos cosas: muestra el formulario (cuando alguien llega) y
 * lo procesa (cuando alguien lo manda). Tenerlo todo junto es lo más simple y
 * además permite volver a mostrar lo que la persona escribió si algo salió mal.
 *
 * Si el reporte se guarda bien, se manda al ciudadano a la página del folio.
 * Se hace así (en vez de mostrar el resultado aquí mismo) para que si recarga
 * la página no se guarde el reporte dos veces.
 */

require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/plantilla.php';

$errores = array();
$nombre  = '';
$colonia = '';
$texto   = '';

// ---------------------------------------------------------------------------
//  Llegó un envío
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre  = isset($_POST['nombre'])  ? trim($_POST['nombre'])  : '';
    $colonia = isset($_POST['colonia']) ? trim($_POST['colonia']) : '';
    $texto   = isset($_POST['texto'])   ? trim($_POST['texto'])   : '';

    // --- Revisar lo que llegó -------------------------------------------
    $largo = mb_strlen($texto, 'UTF-8');

    if ($largo === 0) {
        $errores[] = 'Escribe el problema que quieres reportar.';
    } elseif ($largo < 15) {
        $errores[] = 'El reporte es muy corto. Explica un poco más qué está pasando.';
    } elseif ($largo > 2000) {
        $errores[] = 'El reporte es muy largo. El máximo son 2000 caracteres.';
    }

    if (mb_strlen($nombre, 'UTF-8') > 120) {
        $errores[] = 'El nombre es demasiado largo.';
    }

    if (mb_strlen($colonia, 'UTF-8') > 120) {
        $errores[] = 'El nombre de la colonia es demasiado largo.';
    }

    // --- Que nadie mande cien reportes seguidos -------------------------
    if (empty($errores)) {
        if (reportes_por_ip(ip_visitante()) >= LIMITE_POR_HORA) {
            $errores[] = 'Ya enviaste varios reportes en la última hora. '
                       . 'Espera un rato antes de mandar otro.';
        }
    }

    // --- Guardar ---------------------------------------------------------
    if (empty($errores)) {
        $pdo = conectar();

        // Primero se guarda sin folio, porque el folio lleva el número que
        // MySQL asigna al insertar. Después se actualiza.
        $insertar = $pdo->prepare(
            'INSERT INTO quejas
                 (folio, nombre, colonia, texto, estado, ip, creado_en)
             VALUES
                 (NULL, :nombre, :colonia, :texto, :estado, :ip, NOW())'
        );

        $insertar->execute(array(
            ':nombre'  => $nombre,
            ':colonia' => $colonia,
            ':texto'   => $texto,
            ':estado'  => 'pendiente',
            ':ip'      => ip_visitante(),
        ));

        $id    = (int) $pdo->lastInsertId();
        $folio = formar_folio($id);

        $pdo->prepare('UPDATE quejas SET folio = :folio WHERE id = :id')
            ->execute(array(':folio' => $folio, ':id' => $id));

        // --- Clasificar --------------------------------------------------
        // Si el clasificador está apagado o no contesta, no pasa nada: el
        // reporte ya quedó guardado y se queda en "pendiente" para que alguien
        // lo revise desde el panel.
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
        }

        // --- Al ciudadano -------------------------------------------------
        // Se manda a la página del folio. El "nuevo=1" es solo para que esa
        // página sepa que acaba de enviarse y salude distinto que si alguien
        // estuviera consultando un folio viejo.
        header('Location: reporte.php?folio=' . urlencode($folio) . '&nuevo=1');
        exit;
    }
}

// ---------------------------------------------------------------------------
//  Mostrar el formulario
// ---------------------------------------------------------------------------
encabezado('Reportar un problema');
?>

<h1>Reporta un problema en tu colonia</h1>

<p class="entrada">
    Cuéntanos qué está pasando. El sistema lee tu reporte y lo manda al área
    que le toca atenderlo. Al final te da un folio para que puedas seguirle
    el rastro.
</p>

<?php foreach ($errores as $mensaje): ?>
    <?php aviso($mensaje, 'error'); ?>
<?php endforeach; ?>

<form method="post" action="index.php" class="tarjeta">

    <div class="campo">
        <label for="texto">¿Qué está pasando? <span class="obligatorio">*</span></label>
        <textarea id="texto" name="texto" rows="7" maxlength="2000"
                  placeholder="Por ejemplo: desde hace una semana no pasa el camión de la basura por la calle de Reforma, entre Hidalgo y Juárez. Ya hay mucha basura acumulada."
        ><?= e($texto) ?></textarea>
        <p class="ayuda">Mínimo 15 caracteres, máximo 2000. Entre más claro, mejor se puede atender.</p>
    </div>

    <div class="campo">
        <label for="colonia">Colonia o calle</label>
        <input type="text" id="colonia" name="colonia" maxlength="120"
               value="<?= e($colonia) ?>" placeholder="Ej. Colonia Centro, calle Reforma">
    </div>

    <div class="campo">
        <label for="nombre">Tu nombre</label>
        <input type="text" id="nombre" name="nombre" maxlength="120"
               value="<?= e($nombre) ?>" placeholder="Opcional">
        <p class="ayuda">Si lo dejas vacío, el reporte se registra como anónimo.</p>
    </div>

    <button type="submit" class="boton">Enviar reporte</button>
</form>

<?php pie(); ?>
