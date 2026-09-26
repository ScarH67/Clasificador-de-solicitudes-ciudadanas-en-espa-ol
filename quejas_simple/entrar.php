<?php
/**
 * Entrada al panel. Solo para el personal.
 *
 * Las contraseñas se guardan cifradas con password_hash() y se comparan con
 * password_verify(). Nunca se guarda la contraseña tal cual, ni se puede
 * recuperar: si alguien la olvida, se le asigna otra con crear-admin.php.
 */

require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/plantilla.php';

iniciar_sesion();

// Si ya había entrado, no tiene caso pedirle la contraseña otra vez.
if (usuario_actual() !== null) {
    header('Location: panel.php');
    exit;
}

$error   = '';
$usuario = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $clave   = isset($_POST['clave'])   ? $_POST['clave'] : '';

    if ($usuario === '' || $clave === '') {
        $error = 'Escribe tu usuario y tu contraseña.';
    } else {
        $consulta = conectar()->prepare(
            'SELECT id, usuario, nombre, clave_hash FROM usuarios
              WHERE usuario = :usuario AND activo = 1 LIMIT 1'
        );
        $consulta->execute(array(':usuario' => $usuario));
        $fila = $consulta->fetch();

        // El mismo mensaje tanto si el usuario no existe como si la
        // contraseña está mal. Así nadie puede averiguar qué usuarios hay.
        if ($fila === false || !password_verify($clave, $fila['clave_hash'])) {
            $error = 'El usuario o la contraseña no son correctos.';

            // Medio segundo de pausa en cada intento fallido. Es poco, pero
            // hace que probar claves a la fuerza sea muchísimo más lento.
            usleep(400000);
        } else {
            // Se cambia el identificador de la sesión al entrar, para que no
            // se pueda aprovechar uno que alguien hubiera fijado antes.
            session_regenerate_id(true);

            $_SESSION['usuario_id']           = $fila['id'];
            $_SESSION['usuario_nombre_corto'] = $fila['usuario'];
            $_SESSION['usuario_nombre']       = $fila['nombre'];

            header('Location: panel.php');
            exit;
        }
    }
}

encabezado('Entrar');
?>

<div class="acceso">
    <h1>Entrar</h1>
    <p class="entrada">Acceso para el personal que atiende los reportes.</p>

    <?php aviso($error, 'error'); ?>

    <form method="post" action="entrar.php" class="tarjeta">
        <div class="campo">
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" value="<?= e($usuario) ?>"
                   autocomplete="username" required autofocus>
        </div>

        <div class="campo">
            <label for="clave">Contraseña</label>
            <input type="password" id="clave" name="clave"
                   autocomplete="current-password" required>
        </div>

        <button type="submit" class="boton">Entrar</button>
    </form>
</div>

<?php pie(); ?>
