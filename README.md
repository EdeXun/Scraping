# Radar · seguimiento de productos

Aplicación con frontend React, API Laravel y SQLite local. Cada producto tiene un intervalo independiente de 5, 15 o 30 minutos.

## Requisitos

- PHP 8.3 o superior con extensiones PDO SQLite, cURL y DOM
- Composer
- Node.js y npm
- Docker Desktop para ejecutar Maxun

## Backend Laravel

El backend está en `backend/` y usa el `.env` que Composer generó para Laravel. La configuración local apunta a `backend/database/database.sqlite`; esa base conserva los datos SQLite del backend anterior.

Desde la raíz del proyecto, inicia la API:

```powershell
cd backend
php artisan migrate
php artisan serve --host=localhost --port=8000
```

La API queda en `http://localhost:8000/api`. También puedes ejecutar `php artisan list` para ver los comandos disponibles. `php artisan serve` es el comando correcto porque el backend ahora sí es Laravel.

Para usar MySQL, cambia `DB_CONNECTION` y configura `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` en `backend/.env`; después ejecuta `php artisan migrate`.

Para que el historial se actualice automáticamente, deja activo el planificador en otra terminal:

```powershell
php artisan schedule:work
```

El planificador revisa cada minuto si algún producto ya venció; cada producto conserva su intervalo individual de 5, 15 o 30 minutos. También puedes ejecutar una revisión puntual con `php artisan radar:check-products`.

## Frontend

Desde la raíz:

```powershell
cd frontend
npm install
npm run dev
```

Abre `http://localhost:5173`. Durante el desarrollo, Vite reenvía las solicitudes `/api` a Laravel en `http://localhost:8000`. Para usar otra dirección, configura `VITE_API_URL` en el frontend.

## Maxun (motor de extracción)

El repositorio oficial está descargado en `maxun/`. Se ejecuta como una aplicación aparte de Radar:

```powershell
cd maxun
docker compose up -d
```

Abre el panel de Maxun en `http://localhost:5174`; su API queda en `http://localhost:8080`. Radar crea un robot de tipo Scrape para cada producto y ejecuta ese robot al agregarlo o al pulsar actualizar. Después procesa el HTML que devuelve Maxun para completar la tarjeta del producto.

Para conectar Maxun, genera o copia la clave desde **Clave API** en el panel y colócala en `backend/.env`:

```dotenv
MAXUN_API_URL=http://localhost:8080/api
MAXUN_API_KEY=pega_aqui_tu_clave
```

La clave se usa solo en Laravel; no la compartas ni la pongas en el frontend. Reinicia `php artisan serve` después de guardarla. Para detener los servicios Maxun sin borrar los datos persistentes:

```powershell
docker compose down
```

La configuración local está en `maxun/.env` (ignorada por Git). Maxun se distribuye con licencia AGPL-3.0; revisa sus condiciones antes de distribuir una versión modificada o incorporarlo a un producto comercial.

## Notas de extracción

Radar extrae nombre, precio e imagen del HTML que Maxun devuelve, usando JSON-LD (Schema.org/Product), Open Graph y metadatos comunes. La extracción no se puede garantizar en todos los sitios: algunos bloquean el acceso o requieren una adaptación específica. Respeta los términos y límites de acceso del sitio consultado.

El backend anterior se conserva localmente en `backend-native-backup/` como respaldo de la conversión.
