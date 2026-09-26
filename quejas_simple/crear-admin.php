<?php
/**
 * Crea o actualiza un usuario del panel.
 *
 * Esto no se puede hacer desde el archivo .sql porque la contraseña tiene que
 * cifrarse con bcrypt, y eso solo lo sabe hacer PHP. Por eso hay este script.
 *
 * Cómo se usa (pregunta lo que falte):
 *
 *     php crear-admin.php
 *
 * O de una sola vez:
 *
 *     php crear-admin.php --usuario=ana --nombre="Ana Ruiz" --clave=UnaClaveLarga2026
 *
 * Se puede correr las veces que haga falta. Si el usuario ya existe, le
 * actualiza el nombre y la contraseña.
 */

// Solo desde la consola. Si alguien abre este archivo desde el navegador, se
// corta aquí y no pasa nada.
if (PHP_SAPI !== 'cli') {
    exit('Esta utilidad solo se puede ejecutar desde la consola.');
}

require_once __DIR__ . '/funciones.php';

// ---------------------------------------------------------------------------
//  Leer los argumentos
// ---------------------------------------------------------------------------
$usuario = '';
$nombre  = '';
$clave   = '';

foreach (array_slice($argv, 1) as $argumento) {

    if (strpos($argumento, '--') !== 0) {
        continue;
    }

    $partes = explode('=', substr($argumento, 2), 2);
    $llave  = $partes[0];
    $valor  = isset($partes[1]) ? $partes[1] : '';

    if ($llave === 'usuario') {
        $usuario = trim($valor);
    } elseif ($llave === 'nombre') {
        $nombre = trim($valor);
    } elseif ($llave === 'clave') {
        $clave = $valor;
    } elseif ($llave === 'ayuda' || $llave === 'h' || $llave === 'help') {
        echo "\nUso: php crear-admin.php [--usuario=X] [--nombre=X] [--clave=X]\n\n";
        echo "  --usuario   nombre con el que se entra al panel\n";
        echo "  --nombre    nombre completo, el que se ve adentro\n";
        echo "  --clave     contraseña. Si no se pasa, se pregunta.\n\n";
        exit(0);
    }
}

// ---------------------------------------------------------------------------
//  Preguntar lo que falte
// ---------------------------------------------------------------------------
function preguntar($texto)
{
    echo $texto;
    $linea = fgets(STDIN);

    return $linea === false ? '' : trim($linea);
}

if ($usuario === '') {
    $usuario = preguntar('Usuario: ');
}

if ($nombre === '') {
    $nombre = preguntar('Nombre completo: ');
}

if ($clave === '') {
    $clave = preguntar('Contraseña: ');
    echo "\n";
}

// ---------------------------------------------------------------------------
//  Revisar que sirvan
// ---------------------------------------------------------------------------
$problemas = array();

if ($usuario === '') {
    $problemas[] = 'El usuario no puede ir vacío.';
} elseif (strlen($usuario) > 60) {
    $problemas[] = 'El usuario es demasiado largo (máximo 60).';
} elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $usuario)) {
    $problemas[] = 'El usuario solo puede llevar letras, números, punto, guion y guion bajo.';
}

if ($nombre === '') {
    $problemas[] = 'El nombre no puede ir vacío.';
} elseif (strlen($nombre) > 150) {
    $problemas[] = 'El nombre es demasiado largo (máximo 150).';
}

if (strlen($clave) < 8) {
    $problemas[] = 'La contraseña tiene que tener al menos 8 caracteres.';
}

if (!empty($problemas)) {
    echo "\nNo se pudo crear el usuario:\n";
    foreach ($problemas as $problema) {
        echo '  - ' . $problema . "\n";
    }
    echo "\n";
    exit(1);
}

// Aviso si la contraseña es de las que todo mundo usa. No se impide, pero se
// dice, porque un panel abierto a internet con clave "12345678" es un problema.
$faciles = array('12345678', 'password', 'admin123', '123456789', 'qwertyui');
if (in_array(strtolower($clave), $faciles)) {
    echo "\nOjo: esa contraseña es de las más usadas del mundo. Mejor pon otra.\n\n";
}

// ---------------------------------------------------------------------------
//  Guardar
// ---------------------------------------------------------------------------
try {
    $pdo = conectar();
} catch (Exception $e) {
    echo "\nNo se pudo conectar a la base de datos. Revisa config.php.\n\n";
    exit(1);
}

$hash = password_hash($clave, PASSWORD_DEFAULT);

$consulta = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = :usuario LIMIT 1');
$consulta->execute(array(':usuario' => $usuario));
$id = $consulta->fetchColumn();

if ($id === false) {
    $pdo->prepare(
        'INSERT INTO usuarios (usuario, nombre, clave_hash, activo, creado_en)
         VALUES (:usuario, :nombre, :hash, 1, NOW())'
    )->execute(array(':usuario' => $usuario, ':nombre' => $nombre, ':hash' => $hash));

    echo "\nUsuario '$usuario' creado.\n";
} else {
    $pdo->prepare(
        'UPDATE usuarios SET nombre = :nombre, clave_hash = :hash, activo = 1 WHERE id = :id'
    )->execute(array(':nombre' => $nombre, ':hash' => $hash, ':id' => $id));

    echo "\nUsuario '$usuario' actualizado.\n";
}

// ---------------------------------------------------------------------------
//  Comprobar que de verdad funciona
// ---------------------------------------------------------------------------
//  No basta con haber guardado: se prueba la contraseña contra lo que quedó en
//  la base, para no dejar al usuario con unas credenciales que no sirven.
$consulta = $pdo->prepare('SELECT clave_hash FROM usuarios WHERE usuario = :usuario LIMIT 1');
$consulta->execute(array(':usuario' => $usuario));
$guardado = $consulta->fetchColumn();

if ($guardado !== false && password_verify($clave, $guardado)) {
    echo "Comprobado: las credenciales funcionan.\n\n";
} else {
    echo "Algo salió mal: la contraseña no coincide. Intenta otra vez.\n\n";
    exit(1);
}
