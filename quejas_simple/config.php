<?php
/**
 * Configuración.
 *
 * Este es el ÚNICO archivo que hay que tocar al pasar el sistema al servidor.
 * Todo lo demás funciona igual en tu computadora y en CentOS.
 *
 * Se usan constantes (define) en vez de una clase o un arreglo para que los
 * valores estén disponibles desde cualquier archivo sin tener que andar
 * pasando variables de un lado a otro.
 *
 * Nota: este archivo no imprime nada. Si alguien lo abre desde el navegador,
 * la página sale en blanco y no se ve la contraseña ni nada. Aun así, en el
 * servidor conviene bloquearlo desde nginx (viene explicado en el LEEME).
 */

// ---------------------------------------------------------------------------
//  Base de datos
// ---------------------------------------------------------------------------
//  OJO CON ESTO, que es la causa del error más común al pasar a Docker:
//
//  El host NO es una dirección IP. En Docker, cada contenedor es como una
//  computadora aparte, y "127.0.0.1" dentro de un contenedor significa ESE
//  contenedor, no el servidor ni la base de datos. Para llegar a la base hay
//  que usar el NOMBRE DEL CONTENEDOR, y los dos tienen que estar en la misma
//  red de Docker.
//
//  Por eso aquí va "legacy_mysql56", que es el nombre del contenedor de MySQL
//  5.6 que ya usas en tu proyecto anterior. Es el mismo patrón que te funciona.
//
//  Si algún día corres la app fuera de Docker, cambia esto por '127.0.0.1'.
//
//  OJO, no confundir esto con conectarse desde un cliente MySQL en tu
//  computadora. Son dos caminos distintos a la misma base:
//
//    desde la app (dentro de Docker) -> legacy_mysql56, puerto 3306
//    desde tu computadora (Workbench) -> 192.168.100.3, puerto 3307
//
//  El 3307 es el puerto que el servidor publica hacia la red. Aquí no se pone
//  porque la app entra por dentro, y por dentro siempre es el 3306.
// ---------------------------------------------------------------------------

// --- Para el servidor CentOS, con todo en Docker ---------------------------
define('BD_HOST',    'legacy_mysql56');   // nombre del contenedor, no una IP
define('BD_NOMBRE',  'reportes_ciudadanos');
define('BD_USUARIO', 'root');
define('BD_CLAVE',   'root123');

// --- Para probar en esta computadora (XAMPP), descomenta y comenta las de
//     arriba. Aquí sí es una IP porque todo corre en la misma máquina.
//
// define('BD_HOST',    '127.0.0.1');
// define('BD_NOMBRE',  'reportes_ciudadanos');
// define('BD_USUARIO', 'reportes');
// define('BD_CLAVE',   '');

// ---------------------------------------------------------------------------
//  Clasificador
// ---------------------------------------------------------------------------
// La dirección de la computadora que corre el modelo. El puerto 8000 es el que
// usa la API. Esta IP hay que cambiarla por la de tu equipo de verdad.
define('CLASIFICADOR_URL', 'http://192.168.100.10:8000');

// Cuántos segundos esperar la respuesta antes de darse por vencido. Si el
// clasificador no contesta a tiempo, el reporte se guarda igual y queda
// marcado como "pendiente".
define('CLASIFICADOR_ESPERA', 15);

// ---------------------------------------------------------------------------
//  Sitio
// ---------------------------------------------------------------------------
define('SITIO_NOMBRE', 'Reporte Ciudadano');
define('SITIO_DEPENDENCIA', 'Gobierno de la Ciudad de Exico');

// Cuántos reportes se muestran por página en el panel.
define('REPORTES_POR_PAGINA', 20);

// Cuántos reportes puede mandar una misma computadora en una hora. Sirve para
// que nadie llene la base de basura. Si sale estorbando, se sube o se quita.
define('LIMITE_POR_HORA', 10);
