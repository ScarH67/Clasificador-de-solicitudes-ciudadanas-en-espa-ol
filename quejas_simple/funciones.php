<?php
/**
 * Funciones que usan todas las páginas.
 *
 * Aquí está lo que se repite: conectarse a la base, escapar el texto que se
 * muestra, llamar al clasificador y manejar la sesión del panel.
 *
 * No hay clases ni objetos. Son funciones sueltas, para que se pueda leer de
 * arriba abajo sin saltar entre archivos.
 */

require_once __DIR__ . '/config.php';

// ===========================================================================
//  Base de datos
// ===========================================================================

/**
 * Devuelve la conexión a MySQL. Se abre una sola vez por petición.
 *
 * Las consultas siempre van con parámetros (prepare + execute), nunca pegando
 * el texto del usuario dentro del SQL. Eso es lo que evita la inyección SQL.
 */
function conectar()
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . BD_HOST . ';dbname=' . BD_NOMBRE . ';charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, BD_USUARIO, BD_CLAVE, array(
                // Que los errores salten como excepciones en vez de fallar en
                // silencio. Es más fácil de depurar.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Sentencias preparadas de verdad, no simuladas por PHP.
                // Ojo: con esto, un mismo :parametro no se puede usar dos veces
                // en la misma consulta. Hay que poner dos nombres distintos.
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        } catch (PDOException $e) {
            // No se muestra el error real al visitante: podría llevar la
            // contraseña de la base. Se guarda en el log y se corta.
            error_log('No se pudo conectar a la base de datos: ' . $e->getMessage());
            exit('No se pudo conectar a la base de datos. Revisa config.php.');
        }
    }

    return $pdo;
}

// ===========================================================================
//  Texto
// ===========================================================================

/**
 * Escapa texto para mostrarlo en HTML.
 *
 * Se usa SIEMPRE que se imprime algo que escribió el usuario. Sin esto,
 * cualquiera puede meter etiquetas <script> en un reporte y ejecutar código
 * en el navegador de quien lo lea.
 */
function e($texto)
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

/** Corta un texto largo y le pone puntos suspensivos. */
function recortar($texto, $largo = 120)
{
    if (mb_strlen($texto, 'UTF-8') <= $largo) {
        return $texto;
    }
    return mb_substr($texto, 0, $largo, 'UTF-8') . '...';
}

/** Convierte 2026-09-20 14:30:00 en 20/09/2026 14:30. */
function fecha_legible($fecha)
{
    if (empty($fecha)) {
        return '';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

// ===========================================================================
//  Categorías
// ===========================================================================

/**
 * Las cinco categorías que devuelve el clasificador.
 *
 * La llave de la izquierda tiene que coincidir EXACTAMENTE con el campo
 * "categoria" que manda la API. Si no coincide, el reporte se guarda pero sin
 * nombre que mostrar.
 *
 * Para agregar una categoría nueva: primero hay que entrenar el modelo con
 * ella, y después agregarla aquí.
 */
function categorias()
{
    return array(
        'baches_y_pavimento' => 'Baches y pavimento',
        'alumbrado_publico'  => 'Alumbrado público',
        'fuga_de_agua'       => 'Agua y drenaje',
        'recoleccion_basura' => 'Recolección de basura',
        'seguridad'          => 'Seguridad',
    );
}

/** El nombre bonito de una categoría. Si no la conoce, devuelve la clave. */
function nombre_categoria($clave)
{
    $todas = categorias();

    if (isset($todas[$clave])) {
        return $todas[$clave];
    }

    return $clave;
}

/** El área a la que le toca atender cada categoría. */
function area_categoria($clave)
{
    $areas = array(
        'baches_y_pavimento' => 'Obras Públicas',
        'alumbrado_publico'  => 'Servicios Urbanos',
        'fuga_de_agua'       => 'SACMEX',
        'recoleccion_basura' => 'Servicios Urbanos',
        'seguridad'          => 'Seguridad Ciudadana',
    );

    return isset($areas[$clave]) ? $areas[$clave] : 'Por asignar';
}

// ===========================================================================
//  Clasificador
// ===========================================================================

/**
 * Le pregunta al clasificador a qué categoría pertenece un texto.
 *
 * Devuelve siempre un arreglo con 'ok'. Si algo falla, 'ok' viene en false y
 * el motivo en 'error'. Nunca lanza excepción ni se cae: si el clasificador
 * está apagado, el reporte se guarda igual y se marca para revisar después.
 */
function clasificar($texto)
{
    $ch = curl_init(CLASIFICADOR_URL . '/clasificar');

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('texto' => $texto)));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Tiempo máximo para conectar y para recibir la respuesta.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, CLASIFICADOR_ESPERA);

    // Esto es importante y no es obvio: si el servidor tiene definida la
    // variable de entorno http_proxy (pasa en redes de oficina), cURL manda
    // las llamadas por ahí y nunca llegan al clasificador. Como el
    // clasificador está en la red local, se desactiva el proxy a la fuerza.
    curl_setopt($ch, CURLOPT_PROXY, '');
    curl_setopt($ch, CURLOPT_NOPROXY, '*');

    $respuesta = curl_exec($ch);
    $codigo    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($respuesta === false) {
        return array('ok' => false, 'error' => 'No hubo conexión: ' . $error,
                     'categoria' => '', 'confianza' => 0, 'fuera_de_alcance' => false);
    }

    if ($codigo < 200 || $codigo >= 300) {
        return array('ok' => false, 'error' => 'El clasificador respondió HTTP ' . $codigo,
                     'categoria' => '', 'confianza' => 0, 'fuera_de_alcance' => false);
    }

    $datos = json_decode($respuesta, true);

    if (!is_array($datos)) {
        return array('ok' => false, 'error' => 'La respuesta no era JSON válido',
                     'categoria' => '', 'confianza' => 0, 'fuera_de_alcance' => false);
    }

    return array(
        'ok'              => true,
        'error'           => '',
        'categoria'       => isset($datos['categoria']) ? $datos['categoria'] : '',
        'confianza'       => isset($datos['confianza']) ? (float) $datos['confianza'] : 0,
        'fuera_de_alcance' => !empty($datos['posible_fuera_de_alcance']),
    );
}

/** Pregunta si el clasificador está vivo. Se usa en el panel. */
function clasificador_vivo()
{
    $ch = curl_init(CLASIFICADOR_URL . '/salud');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_PROXY, '');
    curl_setopt($ch, CURLOPT_NOPROXY, '*');

    $respuesta = curl_exec($ch);
    $codigo    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($respuesta === false || $codigo < 200 || $codigo >= 300) {
        return array('ok' => false, 'modelo' => '');
    }

    $datos = json_decode($respuesta, true);

    return array(
        'ok'     => true,
        'modelo' => isset($datos['modelo']) ? $datos['modelo'] : 'desconocido',
    );
}

// ===========================================================================
//  Reportes
// ===========================================================================

/** Arma el folio que se le muestra al ciudadano. Ejemplo: CDMX-2026-000123. */
function formar_folio($id)
{
    return sprintf('CDMX-%s-%06d', date('Y'), $id);
}

/**
 * Los estados por los que pasa un reporte, en orden.
 *
 * La llave es lo que se guarda en la base; el valor es lo que se muestra.
 */
function estados_posibles()
{
    return array(
        'pendiente'  => 'Pendiente de revisión',
        'canalizado' => 'Enviado al área',
        'en_proceso' => 'En proceso',
        'atendido'   => 'Atendido',
    );
}

/** El nombre bonito de un estado. */
function etiqueta_estado($estado)
{
    $todos = estados_posibles();

    return isset($todos[$estado]) ? $todos[$estado] : $estado;
}

/** Cuántos reportes se han recibido desde una misma IP en la última hora. */
function reportes_por_ip($ip)
{
    $sql = 'SELECT COUNT(*) FROM quejas
            WHERE ip = :ip AND creado_en > (NOW() - INTERVAL 1 HOUR)';

    $consulta = conectar()->prepare($sql);
    $consulta->execute(array(':ip' => $ip));

    return (int) $consulta->fetchColumn();
}

// ===========================================================================
//  Sesión del panel
// ===========================================================================

/** Enciende la sesión. Se llama al principio de las páginas del panel. */
function iniciar_sesion()
{
    if (session_id() === '') {
        session_start();
    }
}

/** El usuario que tiene la sesión abierta, o null si nadie ha entrado. */
function usuario_actual()
{
    iniciar_sesion();

    if (isset($_SESSION['usuario_id'])) {
        return array(
            'id'     => $_SESSION['usuario_id'],
            'usuario' => $_SESSION['usuario_nombre_corto'],
            'nombre' => $_SESSION['usuario_nombre'],
        );
    }

    return null;
}

/**
 * Corta la página si nadie ha entrado al panel.
 * Se pone arriba de panel.php, después de iniciar_sesion().
 */
function exigir_sesion()
{
    if (usuario_actual() === null) {
        header('Location: entrar.php');
        exit;
    }
}

/** El mensaje de aviso que dejó la página anterior, y lo borra. */
function tomar_aviso()
{
    iniciar_sesion();

    if (empty($_SESSION['aviso'])) {
        return '';
    }

    $aviso = $_SESSION['aviso'];
    unset($_SESSION['aviso']);

    return $aviso;
}

/** Deja un mensaje para que lo muestre la página siguiente. */
function dejar_aviso($mensaje)
{
    iniciar_sesion();
    $_SESSION['aviso'] = $mensaje;
}

/** La IP del visitante. */
function ip_visitante()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}
