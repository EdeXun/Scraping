# Radar para Android

Esta app Android abre la versión web publicada de Radar y conserva la lógica que ya usa la aplicación. Necesita conexión a internet; la interfaz y el catálogo usan el servicio de Vercel/Render.

La extracción con Maxun seguirá dependiendo de que Maxun y el túnel desde tu PC estén funcionando y accesibles para Render. Instalar la app no mueve Maxun al teléfono ni elimina esa dependencia.

## Obtener el APK

El flujo de GitHub Actions compila un APK de depuración instalable y lo guarda como artefacto `radar-android-apk`. En GitHub, abre el repositorio, entra a **Actions**, selecciona **Build Radar Android APK**, abre la ejecución más reciente y descarga ese artefacto. Extrae el ZIP y abre `app-debug.apk` en el teléfono para instalarlo; Android puede pedir permiso para instalar aplicaciones desde esa fuente.

Este APK de depuración sirve para instalación directa en tu teléfono. No está preparado como lanzamiento firmado para Google Play.
