<?php
/**
 * Configuración.
 *
 * Este es el ÚNICO archivo que hay que tocar al pasar el sistema al servidor.
 * Todo lo demás funciona igual en tu computadora y en el servidor.
 *
 * Se usan constantes (define) en vez de una clase o un arreglo para que los
 * valores estén disponibles desde cualquier archivo sin tener que andar
 * pasando variables de un lado a otro.
 *
 * Nota: este archivo no imprime nada. Si alguien lo abre desde el navegador,
 * la página sale en blanco y no se ve la contraseña ni nada. Aun así, en el
 * servidor conviene bloquearlo desde Apache (viene explicado en el LEEME).
 */

// ---------------------------------------------------------------------------
//  Zona horaria
// ---------------------------------------------------------------------------
// México está en UTC-6 y ya no cambia de horario desde 2022.
//
// Aquí va "Etc/GMT+6" y NO "America/Mexico_City", por una razón concreta: este
// PHP trae una tabla de zonas horarias de 2014 (se puede comprobar con
// timezone_version_get()), de antes de que México dejara de cambiar de horario.
// Con el nombre "America/Mexico_City" el PHP cree que en septiembre todavía hay
// horario de verano y calcula una hora de más. "Etc/GMT+6" es un desplazamiento
// fijo, así que da la hora correcta todo el año.
//
// Ojo con el nombre: en esta nomenclatura el signo va al revés, "GMT+6"
// significa UTC-6.
define('ZONA_HORARIA', 'Etc/GMT+6');
date_default_timezone_set(ZONA_HORARIA);

// ---------------------------------------------------------------------------
//  Base de datos
// ---------------------------------------------------------------------------
define('BD_HOST',    'legacy_mysql56');   // nombre del contenedor, no una IP
define('BD_NOMBRE',  'reportes_ciudadanos');
define('BD_USUARIO', 'root');
define('BD_CLAVE',   'root123');

// En tu computadora (XAMPP) el usuario root no tiene contraseña. En el
// servidor hay que poner aquí la que le hayas dado al usuario de la base.

// La zona horaria con la que la base guarda las fechas.
//
// Esto va aparte de ZONA_HORARIA (la de arriba) porque las fechas de los
// reportes NO las escribe PHP: las escribe MySQL con NOW(), al momento del
// INSERT. O sea que la hora que se guarda es la del contenedor de MySQL, no la
// del sitio. El contenedor está en UTC, así que sin esto la hora sale seis
// horas adelantada y PHP no la corrige sola: la muestra tal cual llegó.
//
// Se pone el desplazamiento numérico (-06:00) y no el nombre
// (America/Mexico_City) a propósito: el nombre exige que el servidor tenga
// cargadas las tablas de zonas horarias, y la imagen de MySQL no las trae.
// México ya no cambia de horario desde 2022, así que -06:00 sirve todo el año.
define('BD_ZONA_HORARIA', '-06:00');


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

// A partir de qué confianza damos por buena la clasificación. Por debajo de
// esto, el reporte se marca para que una persona lo revise.
//
// La API ya trae su propio umbral, pero no es el mismo en los dos modelos:
// BETO usa 0.90 y el baseline 0.60. Se repite aquí a propósito, para que el
// sitio se comporte igual sin importar cuál de los dos esté levantado.
define('UMBRAL_REVISION', 0.90);

// ---------------------------------------------------------------------------
//  Sitio
// ---------------------------------------------------------------------------
define('SITIO_NOMBRE', 'Reporte Ciudadano');

// Lo que sale en el pie de todas las páginas. Antes decía "Gobierno de la
// Ciudad de México" y se quitó a propósito: este sitio es un trabajo de
// titulación, no un servicio del gobierno, y no conviene que se confunda con
// uno.
define('SITIO_AVISO', 'Proyecto universitario — Maestría en Inteligencia Artificial (UNIR)');

// La línea chica que va debajo del nombre del sitio, en la cabecera.
define('SITIO_LEYENDA', 'Prototipo académico de clasificación de solicitudes ciudadanas');

// Cuántos reportes se muestran por página en el panel.
define('REPORTES_POR_PAGINA', 20);

// Cuántos reportes puede mandar una misma computadora en una hora. Sirve para
// que nadie llene la base de basura. Si sale estorbando, se sube o se quita.
define('LIMITE_POR_HORA', 10);
