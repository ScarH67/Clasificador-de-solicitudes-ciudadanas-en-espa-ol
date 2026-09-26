<?php
/**
 * Cerrar la sesión del panel.
 *
 * Borra la sesión entera, no solo el usuario, y destruye la cookie. Si solo se
 * borrara el usuario de $_SESSION, la sesión seguiría viva y bastaría con
 * recuperar el identificador para volver a entrar.
 */

require_once __DIR__ . '/funciones.php';

iniciar_sesion();

// Se vacía lo que haya en la sesión.
$_SESSION = array();

// Y se le dice al navegador que borre la cookie de la sesión.
if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parametros['path'],
        $parametros['domain'],
        $parametros['secure'],
        $parametros['httponly']
    );
}

session_destroy();

header('Location: entrar.php');
exit;
