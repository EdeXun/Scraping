# Radar para Android con Expo

Esta app instala un contenedor Android que abre la versión web publicada en Vercel. La interfaz, el inicio de sesión, el catálogo y la API siguen en sus servicios actuales; no se duplican dentro del APK.

## Crear el APK de prueba

1. Instala Node.js 22.13 o posterior.
2. Entra en esta carpeta y ejecuta `npm install`.
3. Inicia sesión en Expo con `npx eas-cli login` (o crea una cuenta gratuita en [expo.dev](https://expo.dev)). No compartas la contraseña en el repositorio.
4. Ejecuta `npx eas-cli build:configure` una vez para asociar el proyecto con tu cuenta Expo.
5. Ejecuta `npx eas-cli build --platform android --profile preview`.
6. Cuando EAS termine, abre el enlace del build en tu teléfono Android y descarga/instala el APK.

El perfil `preview` genera un APK de distribución interna; EAS proporciona un enlace de instalación. La compilación en la nube requiere una cuenta de Expo.

## Consideraciones

- El teléfono necesita internet para cargar la web.
- Las extracciones dependen de que la API de Render pueda alcanzar Maxun y el túnel configurado desde la PC; el APK no reemplaza ese servicio.
- Para instalar una versión firmada con una clave nueva encima de una app anterior con el mismo identificador, Android podría pedir desinstalar primero la versión anterior.
