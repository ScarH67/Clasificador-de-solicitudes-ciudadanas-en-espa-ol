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
                // Le decimos a MySQL en qué zona horaria estamos, en cada
                // conexión. Las fechas del sitio las escribe la base con NOW(),
                // así que sin esto se guardan en la hora del contenedor (UTC) y
                // luego se muestran tal cual: seis horas adelantadas. Ver la
                // explicación larga en config.php.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . BD_ZONA_HORARIA . "'",
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

/**
 * Las categorías que puede elegir una persona desde el panel.
 *
 * Son las cinco del modelo más una que el modelo no tiene: "Fuera de
 * categoría", para cuando el reporte no encaja en ninguna de las cinco.
 *
 * La clave es "fuera_de_alcance" a propósito, para que sea la misma palabra
 * que usa el guardián de la API. Así, al analizar los resultados, lo que dice
 * el modelo y lo que dice el operador se comparan directo, sin traducir nada.
 */
function categorias_manuales()
{
    $todas = categorias();
    $todas['fuera_de_alcance'] = 'Fuera de categoría';

    return $todas;
}

/** El nombre bonito de una categoría. Si no la conoce, devuelve la clave. */
function nombre_categoria($clave)
{
    $todas = categorias_manuales();

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
 *
 * Además de la categoría, se guarda todo lo que trae el ticket del API:
 * su propio folio, si el guardián levantó la mano, el tiempo que tardó y los
 * metadatos del modelo. Nada de eso se usa para decidir aquí, pero sirve para
 * poder rastrear después de dónde salió cada clasificación.
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
        return clasificacion_vacia('No hubo conexión: ' . $error);
    }

    if ($codigo < 200 || $codigo >= 300) {
        return clasificacion_vacia('El clasificador respondió HTTP ' . $codigo);
    }

    $datos = json_decode($respuesta, true);

    if (!is_array($datos)) {
        return clasificacion_vacia('La respuesta no era JSON válido');
    }

    $categoria  = isset($datos['categoria']) ? $datos['categoria'] : '';
    $confianza  = isset($datos['confianza']) ? (float) $datos['confianza'] : 0;
    $alerta     = !empty($datos['posible_fuera_de_alcance']);
    $estado_api = isset($datos['estado_revision']) ? $datos['estado_revision'] : '';

    return array(
        'ok'               => true,
        'error'            => '',
        'categoria'        => $categoria,
        'confianza'        => $confianza,
        'fuera_de_alcance' => $alerta,

        // El folio que genera la API. Es distinto del folio del sitio, que es
        // el que ve el ciudadano. Este sirve para rastrear la clasificación.
        'folio_api'        => isset($datos['folio']) ? $datos['folio'] : '',

        'estado_revision'  => decidir_revision($confianza, $alerta, $estado_api),
        'tiempo_ms'        => isset($datos['tiempo_ms']) ? (float) $datos['tiempo_ms'] : null,

        // Los metadatos se guardan tal cual vinieron, como texto JSON. Se
        // guardan completos y no repartidos en columnas porque cambian según
        // qué API esté levantada: BETO manda el dispositivo y la temperatura,
        // el baseline no.
        'metadatos'        => isset($datos['metadatos'])
                                ? json_encode($datos['metadatos'], JSON_UNESCAPED_UNICODE)
                                : null,
    );
}

/**
 * El resultado que se devuelve cuando no se pudo clasificar.
 *
 * Se llena aquí y no en cada punto de salida para que todos los caminos de
 * error devuelvan exactamente las mismas llaves. Si a uno solo se le olvidara
 * una, el código que guarda truena con "undefined index".
 */
function clasificacion_vacia($error)
{
    return array(
        'ok'               => false,
        'error'            => $error,
        'categoria'        => '',
        'confianza'        => 0,
        'fuera_de_alcance' => false,
        'folio_api'        => '',
        'estado_revision'  => 'sin_clasificar',
        'tiempo_ms'        => null,
        'metadatos'        => null,
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
 * Pasa una confianza de 0 a 1 a algo que se pueda leer: "90% (0.9)".
 *
 * Se muestran las dos cosas a propósito. El porcentaje lo entiende cualquiera;
 * el valor entre paréntesis es el número tal cual viene en los metadatos del
 * clasificador, y sirve para poder comparar contra el umbral sin hacer cuentas.
 *
 * Los ceros de la derecha se recortan, para que 0.9 no salga como "0.900".
 */
function confianza_legible($confianza)
{
    $valor = (float) $confianza;

    $porcentaje = rtrim(rtrim(number_format($valor * 100, 1, '.', ''), '0'), '.');
    $numero     = rtrim(rtrim(number_format($valor, 3, '.', ''), '0'), '.');

    if ($porcentaje === '') {
        $porcentaje = '0';
    }

    if ($numero === '') {
        $numero = '0';
    }

    return $porcentaje . '% (' . $numero . ')';
}

/**
 * Saca de los metadatos el nombre del modelo que clasificó el reporte.
 *
 * Lo que manda la API es el nombre técnico ("beto_finetunado_calibrado",
 * "tfidf_logreg_calibrado"), que al ciudadano no le dice nada. Aquí se traduce
 * a algo entendible.
 *
 * Si el reporte no se pudo clasificar, los metadatos vienen vacíos y devuelve
 * "Ninguno", que es justo lo que pasó: no hubo modelo.
 *
 * Para agregar un modelo nuevo basta con meter otra condición aquí. Si el
 * nombre no coincide con ninguna, se muestra tal cual vino, que es mejor que
 * poner "desconocido".
 */
function nombre_modelo($metadatos)
{
    if (empty($metadatos)) {
        return 'Ninguno';
    }

    $crudo = json_decode($metadatos, true);

    if (!is_array($crudo) || empty($crudo['modelo'])) {
        return 'Ninguno';
    }

    $clave = strtolower($crudo['modelo']);

    if (strpos($clave, 'beto') !== false) {
        return 'BETO (BERT en español)';
    }

    if (strpos($clave, 'tfidf') !== false
        || strpos($clave, 'tf-idf') !== false
        || strpos($clave, 'logreg') !== false
        || strpos($clave, 'logistic') !== false
        || strpos($clave, 'baseline') !== false) {
        return 'TF-IDF + regresión logística';
    }

    return $crudo['modelo'];
}

/**
 * El tiempo que tardó el clasificador, listo para mostrar: "32 ms".
 *
 * Se redondea porque al ciudadano los decimales no le dicen nada; el número
 * con decimales se sigue guardando completo en la base.
 */
function tiempo_legible($tiempo_ms)
{
    if ($tiempo_ms === null || $tiempo_ms === '') {
        return '—';
    }

    return number_format((float) $tiempo_ms, 0) . ' ms';
}

/**
 * Guarda en la base lo que devolvió el clasificador.
 *
 * Está aquí y no dentro de index.php porque la usan dos lugares: el formulario
 * público y el botón de reclasificar del panel. Así las dos partes guardan
 * exactamente lo mismo y no se van separando con el tiempo.
 *
 * Sobre el estado del trámite: depende de si el reporte quedó con una categoría
 * utilizable, no de la confianza. Que el modelo haya dudado no lo deja sin
 * área; eso se anota aparte, en estado_revision. La excepción es el guardián:
 * si dice que el texto no es de ninguna de las cinco categorías, no hay a dónde
 * canalizarlo y se queda pendiente.
 */
function guardar_clasificacion($id, $resultado)
{
    $estado = $resultado['fuera_de_alcance'] ? 'pendiente' : 'canalizado';

    conectar()->prepare(
        'UPDATE quejas
            SET categoria                = :categoria,
                confianza                = :confianza,
                estado                   = :estado,
                folio_api                = :folio_api,
                posible_fuera_de_alcance = :alerta,
                estado_revision          = :estado_revision,
                tiempo_ms                = :tiempo_ms,
                metadatos                = :metadatos,
                clasificado_manual       = 0
          WHERE id = :id'
    )->execute(array(
        ':categoria'       => $resultado['categoria'],
        ':confianza'       => $resultado['confianza'],
        ':estado'          => $estado,
        ':folio_api'       => $resultado['folio_api'],
        ':alerta'          => $resultado['fuera_de_alcance'] ? 1 : 0,
        ':estado_revision' => $resultado['estado_revision'],
        ':tiempo_ms'       => $resultado['tiempo_ms'],
        ':metadatos'       => $resultado['metadatos'],
        ':id'              => $id,
    ));
}

/**
 * Guarda una clasificación elegida a mano desde el panel.
 *
 * Es distinta de la automática: aquí no hay confianza que valga, la categoría
 * la eligió una persona. Por eso el estado de revisión pasa a "revisado_manual"
 * y clasificado_manual queda en 1.
 *
 * La confianza del modelo NO se borra: se deja como estaba. Sirve para poder
 * comparar después qué dijo el modelo contra qué dijo la persona, que es justo
 * lo que interesa medir.
 *
 * Devuelve false si la categoría no es una de las permitidas.
 */
function guardar_clasificacion_manual($id, $categoria)
{
    if (!array_key_exists($categoria, categorias_manuales())) {
        return false;
    }

    $estado = $categoria === 'fuera_de_alcance' ? 'pendiente' : 'canalizado';

    conectar()->prepare(
        'UPDATE quejas
            SET categoria          = :categoria,
                estado             = :estado,
                estado_revision    = :revision,
                clasificado_manual = 1
          WHERE id = :id'
    )->execute(array(
        ':categoria' => $categoria,
        ':estado'    => $estado,
        ':revision'  => 'revisado_manual',
        ':id'        => $id,
    ));

    return true;
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

/**
 * Los estados por los que pasa la revisión de la clasificación.
 *
 * OJO: esto NO es lo mismo que "estado". "estado" es el avance del trámite y
 * lo ve el ciudadano; "estado_revision" es qué tan confiable salió la
 * clasificación y es cosa del personal. Un reporte puede estar canalizado (ya
 * tiene categoría) y a la vez requerir revisión (el modelo no estaba seguro).
 * Son dos cosas distintas y por eso van en dos columnas distintas.
 */
function estados_revision()
{
    return array(
        'sin_clasificar'    => 'Sin clasificar',
        'automatico'        => 'Clasificado automáticamente',
        'requiere_revision' => 'Requiere revisión',
        'revisado_manual'   => 'Revisado a mano',
    );
}

/** El nombre bonito de un estado de revisión. */
function etiqueta_revision($clave)
{
    $todos = estados_revision();

    return isset($todos[$clave]) ? $todos[$clave] : $clave;
}

/**
 * Decide si un reporte necesita que lo mire una persona.
 *
 * Se queda con el criterio más estricto de los dos: si la API ya dijo que
 * hacía falta revisión, se respeta; y si la confianza no llega al umbral, o el
 * guardián marcó el texto como ajeno a las cinco categorías, también.
 *
 * $estado_api es lo que mandó la API ('automatico' o 'requiere_revision'). Se
 * toma en cuenta pero no se copia tal cual, porque el umbral de la API no es
 * el mismo en los dos modelos y aquí queremos un comportamiento parejo.
 */
function decidir_revision($confianza, $fuera_de_alcance, $estado_api = '')
{
    if ($fuera_de_alcance
        || $estado_api === 'requiere_revision'
        || $confianza < UMBRAL_REVISION) {
        return 'requiere_revision';
    }

    return 'automatico';
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
