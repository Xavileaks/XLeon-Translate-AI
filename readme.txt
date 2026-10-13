=== XLeon Translate AI ===
Contributors: xavileaks
Tags: translation, multilingual, openai, claude
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diccionario compartido de cadenas con edición desde WordPress y traducción opcional mediante tu API de OpenAI o Claude.

== Installation ==
1. Instala xleon-translate-ai.zip desde Plugins > Añadir plugin > Subir plugin.
2. Activa el plugin y abre XLeon Translate.
3. Guarda el idioma original y los destinos.
4. Lee el HTML de una URL pública o abre la captura en navegador.
5. Filtra el diccionario, edita una traducción y guarda: se aplica a todas las coincidencias exactas para ese idioma.
6. Activa el selector al pie o usa [xleon_language_switcher].

== External services ==
Las conexiones de IA son opcionales y se realizan solo al pulsar Consultar modelos, Sugerir con IA o Traducir pendientes con IA. Se envía la clave API únicamente al proveedor correspondiente; al traducir se envían las cadenas, idiomas y modelo. No se envía el HTML completo, cookies, contraseñas ni información de pedidos. Las visitas normales y la captura no llaman a APIs de IA. Cada proveedor puede aplicar cargos y sus propias condiciones.

OpenAI: https://api.openai.com/v1/responses y https://api.openai.com/v1/models
Condiciones: https://openai.com/policies/terms-of-use/
Privacidad: https://openai.com/policies/privacy-policy/
Anthropic: https://api.anthropic.com/v1/messages y https://api.anthropic.com/v1/models
Condiciones: https://www.anthropic.com/legal/commercial-terms
Privacidad: https://www.anthropic.com/legal/privacy

== Changelog ==
= 1.0.0 =
* Primera versión: captura HTML y dinámica, diccionario compartido, edición manual, selector de idiomas y conectores OpenAI / Claude.
