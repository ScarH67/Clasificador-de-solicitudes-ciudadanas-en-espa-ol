# X Exportador de Posts

Userscript para Greasemonkey que permite recopilar publicaciones originales de X desde la sección **“Con respuestas”** y exportarlas en formatos TXT o CSV.

El script está diseñado para guardar únicamente el contenido principal de cada publicación, evitando registrar las respuestas que aparecen dentro de la conversación.

## Características

- Extrae el texto de publicaciones visibles en X.
- Funciona en la sección “Con respuestas”.
- Excluye respuestas y publicaciones de seguimiento.
- Elimina automáticamente menciones como `@usuario`.
- Elimina hashtags como `#TránsitoCDMX`, incluyendo hashtags con acentos.
- Obtiene la fecha y hora de publicación.
- Evita registrar publicaciones duplicadas.
- Acumula publicaciones mientras se desplaza la página.
- Exporta los resultados a TXT.
- Exporta los resultados a CSV compatible con Excel.
- Incluye una interfaz flotante con botones de control.
- Guarda temporalmente los resultados en el almacenamiento local del navegador.
- Incluye comentarios explicativos dentro del código.

## Formato CSV

El archivo CSV generado contiene dos columnas:

```text
fecha_hora,texto
```

Ejemplo:

```text
17/09/2026, 21:25:10,"Está cerrado Avenida 608 dirección Ecatepec..."
```

## Formato TXT

El archivo TXT se genera colocando la fecha y hora antes de cada publicación:

```text
17/09/2026, 21:25:10
Está cerrado Avenida 608 dirección Ecatepec...
```

## Instalación

1. Instala Greasemonkey en Firefox.
2. Crea un nuevo userscript.
3. Copia el contenido del archivo `.user.js`.
4. Guarda los cambios.
5. Abre X e ingresa a la sección **“Con respuestas”**.
6. Desplázate para cargar más publicaciones.
7. Pulsa **Actualizar lista**.
8. Descarga los datos como TXT o CSV.

## Uso

1. Abre la página de publicaciones o respuestas de un perfil en X.
2. Espera a que cargue el contenido.
3. Desplázate hacia abajo para cargar más publicaciones.
4. Utiliza **Actualizar lista** para recopilar los textos visibles.
5. Pulsa **Descargar TXT** o **Descargar CSV**.
6. Usa **Borrar lista** antes de comenzar una nueva recopilación.

## Consideraciones

X modifica periódicamente la estructura de su interfaz. Si cambian los selectores HTML utilizados por la página, puede ser necesario actualizar el userscript.

El script solo recopila publicaciones que estén cargadas y sean visibles en la sesión actual del navegador. No utiliza la API oficial de X ni accede a publicaciones privadas o eliminadas.