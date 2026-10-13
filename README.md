# XLeon Translate AI

Plugin independiente de WordPress para detectar cadenas de texto y editarlas desde el backend. Una misma cadena se guarda una sola vez por idioma: cambiar «Añadir al carrito» aplica esa traducción en todas sus coincidencias exactas, también en otras páginas. La traducción con OpenAI o Claude es opcional; no requiere TranslatePress ni utiliza Google Translate.

## Primera instalación

1. Instala **xleon-translate-ai.zip** desde **Plugins → Añadir plugin → Subir plugin** y actívalo.
2. Abre **XLeon Translate → Idiomas y conexión API**, elige el idioma original y los destinos, y guarda.
3. En **Capturar cadenas de una página**, introduce su URL pública y el destino. **Leer HTML** detecta el HTML generado por WordPress. No necesita clave API.
4. **Captura en navegador** abre la página con una sesión autorizada. Detecta texto, atributos y nuevas cadenas que aparezcan con JavaScript/AJAX; abre los menús o desplegables que quieras capturar. Regresa al backend y pulsa **Filtrar / actualizar**.
5. Busca una cadena, escribe su traducción y pulsa **Guardar para todas las apariciones**. Para volver al original, vacía la traducción y guarda.
6. Muestra el selector al pie desde los ajustes o añade `[xleon_language_switcher]` en un bloque, shortcode o widget Elementor. También puedes abrir una página añadiendo `?xlt_lang=en`.

## IA opcional

Introduce una clave de OpenAI o Anthropic en WordPress, guarda y pulsa **Consultar modelos**. Selecciona un modelo con generación de texto y guarda de nuevo. No se usan credenciales de ChatGPT/Claude en el navegador: son las APIs de cada proveedor, con su propia cuenta y facturación.

**Sugerir con IA** prepara una propuesta para una sola cadena; revísala y guarda. **Traducir pendientes con IA** procesa las cadenas sin traducción de la última página leída, en lotes de hasta diez. No sobrescribe las traducciones existentes. Puedes detenerlo entre lotes; lo ya guardado se conserva. Un fallo no guarda la respuesta inválida y permite reintentar. Si la sesión caduca, vuelve a leer la página para recuperar los pendientes.

Los errores de API se muestran sin exponer claves ni respuestas completas. Los resultados deben ser un array JSON completo, sin HTML y con los marcadores conservados. No hay llamadas de IA por visitas ni traducción automática que genere gasto sin pulsar un botón.

## Detección y alcance de la versión 1.0.0

- Textos del HTML, títulos de pestaña, etiquetas `alt`, `title`, `placeholder`, `aria-label`, botones `input` y metadescripciones. La captura recoge nuevos nodos/atributos tras JavaScript o AJAX. La opción avanzada **Incluir también cadenas internas** captura además mensajes localizados de WordPress/plugins, incluso si no están renderizados; puede añadir muchas filas. Las traducciones de mensajes PHP se aplican también mediante los filtros gettext y plurales.
- Diccionario compartido por idioma original + destino + hash del texto exacto. Ignora espacios al principio y al final; mayúsculas, puntuación y espacios interiores distinguen cadenas. Un texto actualizado queda pendiente, sin heredar una traducción antigua.
- Traducción del HTML en el servidor y de cambios posteriores en el DOM. Los originales de entradas, páginas y productos no se reescriben ni duplican.
- Los enlaces internos de navegación conservan el destino; se excluyen enlaces administrativos, archivos, acciones de carrito y otros enlaces con parámetros ajenos a la navegación.
- Se omiten scripts, estilos, código, SVG, plantillas sin renderizar, campos de usuario y zonas con `translate="no"`, `.notranslate` o `data-xlt-ignore`. Los textos dentro de imágenes, canvas, iframes o Shadow DOM no se detectan en esta versión.
- La lectura HTML obtiene la versión pública anónima, sin reenviar cookies ni autorizaciones. La captura en navegador requiere un administrador; úsala en páginas públicas. No captures contenidos personales ni zonas privadas. Se excluyen carrito, checkout y cuenta de WooCommerce.
- La lectura limita cada documento a 2 MB y cada cadena a 3.500 bytes. Las zonas dinámicas necesitan abrirse durante la captura; no se extraen mensajes que todavía no se hayan renderizado.
- La misma cadena usa una sola traducción por idioma, incluso si aparece en contextos con significados diferentes. Esta versión no incorpora excepciones por contexto.
- Las variantes de idioma son parámetros de URL, con `noindex,follow`. **No incluye URLs SEO por carpetas, slugs traducidos, sitemaps multilingües ni un editor visual equivalente al de TranslatePress.**
- Configura tu caché/CDN para respetar `xlt_lang` o excluir las páginas con ese parámetro. El plugin desactiva la caché de WordPress cuando procesa una variante, pero no controla capas que responden antes de cargar WordPress.

## Claves y datos

Las claves se cifran con AES-256-GCM usando las sales de WordPress y se guardan en una opción sin autoload. Nunca se devuelven a los campos del navegador. Cambiar las sales requiere introducirlas otra vez. El cifrado no protege frente a un administrador con acceso simultáneo a la base de datos y a `wp-config.php`.

Opcionalmente puedes definir `XLT_OPENAI_API_KEY` y `XLT_CLAUDE_API_KEY` en `wp-config.php`; esas constantes tienen prioridad sobre las claves guardadas. La casilla «Borrar clave guardada» solo elimina la copia de la base de datos.

La tabla `{prefijo}xlt_strings` guarda original, traducción, idiomas y primera/última URL de captura. Las cadenas publicadas del diccionario se incluyen en páginas traducidas para aplicar cambios dinámicos. Las traducciones y ajustes se conservan al desactivar o desinstalar el plugin, para evitar perder el trabajo.

## Requisitos y desarrollo

WordPress 6.4+, PHP 8.1+ con DOM, OpenSSL y mbstring. Para IA, conexiones HTTPS salientes a `api.openai.com` o `api.anthropic.com`.

Pruebas sin servicios externos: `php tests/run.php`. Pruebas en WordPress aislado: `php tests/wordpress.php /ruta/a/wordpress`; el entorno debe ser `local`. Los fixtures usan respuestas simuladas y no llaman a APIs de IA. Las pruebas y herramientas de desarrollo se excluyen del ZIP.

Se consultaron las interfaces oficiales: [OpenAI Responses](https://developers.openai.com/api/docs/guides/text) y [Anthropic Messages](https://platform.claude.com/docs/en/api/messages/create).

El ZIP TranslatePress 3.3.8 facilitado por el usuario se revisó como referencia de alcance; este repositorio contiene una implementación propia, sin código ni recursos copiados de ese ZIP.

## Publicación

Las etiquetas `v*` validan PHP, ejecutan las pruebas y generan `xleon-translate-ai.zip` como archivo instalable de GitHub Releases. Instala ese archivo, no el ZIP automático del código fuente. Versión inicial: **1.0.0**.
