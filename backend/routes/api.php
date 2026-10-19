<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Http\Middleware\RequireRadarSession;
use App\Services\MaxunScraper;

Route::middleware('web')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::post('/internal/import-sqlite', function (Request $request) {
        $token = (string) env('RADAR_IMPORT_TOKEN', '');
        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $data = $request->validate([
            'users' => ['required', 'array', 'max:100'],
            'products' => ['required', 'array', 'max:1000'],
            'product_price_history' => ['required', 'array', 'max:10000'],
        ]);

        $columns = [
            'users' => ['id', 'name', 'email', 'password_hash', 'created_at'],
            'products' => ['id', 'user_id', 'url', 'name', 'price', 'image', 'source', 'interval_minutes', 'created_at', 'last_checked_at', 'next_check_at', 'error', 'maxun_robot_id', 'selectors_json'],
            'product_price_history' => ['id', 'product_id', 'name', 'price', 'image', 'captured_at'],
        ];

        $records = [];
        foreach ($columns as $table => $fields) {
            $records[$table] = [];
            foreach ($data[$table] as $record) {
                if (! is_array($record)) {
                    return response()->json(['error' => 'Invalid import record.'], 422);
                }
                $clean = array_intersect_key($record, array_flip($fields));
                if (count($clean) !== count($fields)) {
                    return response()->json(['error' => 'Import record is missing required fields.'], 422);
                }
                $records[$table][] = $clean;
            }
        }

        if (DB::table('users')->exists() || DB::table('products')->exists() || DB::table('product_price_history')->exists()) {
            return response()->json(['error' => 'Import is only allowed into an empty catalog.'], 409);
        }

        DB::transaction(function () use ($records): void {
            foreach (['users', 'products', 'product_price_history'] as $table) {
                foreach (array_chunk($records[$table], 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });

        return response()->json(['imported' => array_map('count', $records)]);
    });

    Route::post('/auth/register', function (Request $request) {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $email = strtolower(trim($data['email']));
        if (DB::table('users')->where('email', $email)->exists()) {
            return response()->json(['error' => 'Ya existe una cuenta con ese correo.'], 409);
        }
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'name' => trim($data['name']),
            'email' => $email,
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'created_at' => now()->utc()->toDateTimeString(),
        ]);
        $request->session()->regenerate();
        $request->session()->put('user_id', $id);

        return response()->json(['user' => ['id' => $id, 'name' => trim($data['name']), 'email' => $email]], 201);
    });

    Route::post('/auth/login', function (Request $request) {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = DB::table('users')->where('email', strtolower(trim($data['email'])))->first();
        if (! $user || ! password_verify($data['password'], $user->password_hash)) {
            return response()->json(['error' => 'Correo o contraseña incorrectos.'], 401);
        }
        $request->session()->regenerate();
        $request->session()->put('user_id', $user->id);

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]]);
    });

    Route::get('/auth/me', function (Request $request) {
        $user = DB::table('users')->where('id', $request->session()->get('user_id'))->first();
        if (! $user) return response()->json(['error' => 'No has iniciado sesión.'], 401);
        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]]);
    });

    Route::post('/auth/logout', function (Request $request) {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true]);
    });

    Route::middleware(RequireRadarSession::class)->group(function (): void {
        Route::post('/products/preview', function (Request $request) {
            $data = $request->validate(['url' => ['required', 'url', 'regex:~^https?://~i']]);
            $maxun = app(MaxunScraper::class);
            if (! $maxun->isConfigured()) {
                return response()->json(['error' => 'Configura MAXUN_API_KEY en backend/.env para conectar el extractor Maxun.'], 503);
            }
            $url = trim($data['url']);
            $robotId = null;
            try {
                $robotId = $maxun->createRobot((string) Str::uuid(), $url);
                $result = $maxun->runRobot($robotId, $url, [], true);
                $token = Str::random(48);
                Cache::put('radar_preview_' . $request->session()->get('user_id') . '_' . $token, [
                    'url' => $url,
                    'robotId' => $result['robotId'] ?? $robotId,
                    'details' => $result['details'],
                ], now()->addMinutes(30));
                return response()->json(['previewToken' => $token, 'html' => $result['previewHtml'], 'screenshot' => $result['previewScreenshot'] ?? null, 'details' => $result['details']]);
            } catch (\Throwable $error) {
                if ($robotId) {
                    try { $maxun->deleteRobot($robotId); } catch (\Throwable) { }
                }
                return response()->json(['error' => 'No se pudo abrir la vista de revisión: ' . $error->getMessage()], 502);
            }
        });

        Route::delete('/products/preview/{token}', function (Request $request, string $token) {
            $key = 'radar_preview_' . $request->session()->get('user_id') . '_' . $token;
            $preview = Cache::pull($key);
            if (is_array($preview) && ! empty($preview['robotId'])) {
                try { app(MaxunScraper::class)->deleteRobot($preview['robotId']); } catch (\Throwable) { }
            }
            return response()->noContent();
        });

        Route::get('/products', function (Request $request) {
            $rows = DB::table('products')->where('user_id', $request->session()->get('user_id'))->orderByDesc('created_at')->get();
            return response()->json($rows->map(fn ($row) => radarProduct($row)));
        });

        Route::get('/history', function (Request $request) {
            $rows = DB::table('product_price_history as history')
                ->join('products', 'products.id', '=', 'history.product_id')
                ->where('products.user_id', $request->session()->get('user_id'))
                ->orderByDesc('history.captured_at')->limit(1000)
                ->get(['history.id', 'history.product_id as productId', 'history.name', 'history.price', 'history.image', 'history.captured_at as capturedAt', 'products.url', 'products.source']);
            return response()->json($rows->map(function ($row) {
                $row->capturedAt = gmdate(DATE_ATOM, strtotime($row->capturedAt . ' UTC'));
                return $row;
            }));
        });

        Route::post('/products', function (Request $request) {
            $data = $request->validate([
                'url' => ['required', 'url', 'regex:~^https?://~i'],
                'intervalMinutes' => ['required', 'integer', 'in:5,15,30'],
                'previewToken' => ['nullable', 'string', 'max:100'],
                'selectors.name' => ['nullable', 'string', 'max:2000'],
                'selectors.price' => ['nullable', 'string', 'max:2000'],
                'selectors.images' => ['nullable', 'array', 'max:8'],
                'selectors.images.*' => ['string', 'max:2000'],
                'selectors.manual' => ['nullable', 'boolean'],
                'selected.name' => ['nullable', 'string', 'max:500'],
                'selected.price' => ['nullable', 'string', 'max:200'],
                'selected.images' => ['nullable', 'array', 'max:8'],
                'selected.images.*' => ['url', 'max:2000'],
            ]);
            $maxun = app(MaxunScraper::class);
            if (! $maxun->isConfigured()) {
                return response()->json(['error' => 'Configura MAXUN_API_KEY en backend/.env para conectar el extractor Maxun.'], 503);
            }
            $id = (string) Str::uuid();
            $now = now()->utc();
            $previewKey = ! empty($data['previewToken']) ? 'radar_preview_' . $request->session()->get('user_id') . '_' . $data['previewToken'] : null;
            $preview = $previewKey ? Cache::pull($previewKey) : null;
            if (! is_array($preview) || ($preview['url'] ?? null) !== trim($data['url'])) {
                if (is_array($preview) && ! empty($preview['robotId'])) {
                    try { $maxun->deleteRobot($preview['robotId']); } catch (\Throwable) { }
                }
                $preview = null;
            }
            $selectors = $data['selectors'] ?? [];
            $selectors['manual'] = true;
            $robotId = $preview['robotId'] ?? null;
            DB::table('products')->insert([
                'id' => $id,
                'user_id' => $request->session()->get('user_id'),
                'url' => trim($data['url']),
                'name' => '', 'price' => '', 'image' => '',
                'source' => (string) parse_url($data['url'], PHP_URL_HOST),
                'interval_minutes' => (int) $data['intervalMinutes'],
                'created_at' => $now->toDateTimeString(),
                'next_check_at' => $now->toDateTimeString(),
                'selectors_json' => json_encode($selectors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            try {
                if (! $robotId) {
                    $robotId = $maxun->createRobot($id, trim($data['url']));
                }
                DB::table('products')->where('id', $id)->update(['maxun_robot_id' => $robotId]);
                if (is_array($preview)) {
                    $details = $preview['details'];
                } else {
                    $result = $maxun->runRobot($robotId, trim($data['url']), $selectors);
                    $details = $result['details'];
                }
                $selected = $data['selected'] ?? [];
                $details['name'] = $selected['name'] ?? '';
                $details['price'] = $selected['price'] ?? '';
                $details['images'] = $selected['images'] ?? [];
                $details['image'] = $details['images'][0] ?? '';
                $missing = missingProductFields($details, $selectors);
                $message = $missing
                    ? 'Robot ejecutado; faltó detectar: ' . implode(', ', $missing) . '.'
                    : 'Robot ejecutado con éxito. Producto agregado al catálogo.';
                DB::table('products')->where('id', $id)->update([
                    'name' => $details['name'], 'price' => $details['price'],
                    'image' => storedProductImages($details), 'source' => $details['source'] ?: (string) parse_url($data['url'], PHP_URL_HOST),
                    'last_checked_at' => now()->utc()->toDateTimeString(),
                    'next_check_at' => now()->utc()->addMinutes((int) $data['intervalMinutes'])->toDateTimeString(),
                    'error' => $missing ? 'No se detectó ' . implode(', ', $missing) . ' en la página.' : null,
                ]);
                recordProductHistory($id, $details, $now->toDateTimeString());
                $product = radarProduct(DB::table('products')->where('id', $id)->first());
                $product['extractionStatus'] = $missing ? 'partial' : 'success';
                $product['robotMessage'] = $message;
                return response()->json($product, 201);
            } catch (\Throwable $error) {
                DB::table('products')->where('id', $id)->update([
                    'maxun_robot_id' => $robotId,
                    'last_checked_at' => now()->utc()->toDateTimeString(),
                    'next_check_at' => now()->utc()->addMinutes((int) $data['intervalMinutes'])->toDateTimeString(),
                    'error' => Str::limit($error->getMessage(), 500),
                ]);
                $product = radarProduct(DB::table('products')->where('id', $id)->first());
                $product['extractionStatus'] = 'error';
                $product['robotMessage'] = 'Se guardó el producto, pero Maxun no pudo terminar la extracción: ' . $error->getMessage();
                return response()->json($product, 201);
            }
        });

        Route::post('/products/{id}/preview', function (Request $request, string $id) {
            $product = DB::table('products')->where('id', $id)->where('user_id', $request->session()->get('user_id'))->first();
            if (! $product) return response()->json(['error' => 'Producto no encontrado.'], 404);
            $maxun = app(MaxunScraper::class);
            if (! $maxun->isConfigured()) return response()->json(['error' => 'Configura MAXUN_API_KEY en backend/.env para conectar el extractor Maxun.'], 503);
            try {
                $robotId = $product->maxun_robot_id ?: $maxun->createRobot($product->id, $product->url);
                $result = $maxun->runRobot($robotId, $product->url, [], true);
                if ($robotId !== ($result['robotId'] ?? $robotId)) {
                    DB::table('products')->where('id', $id)->update(['maxun_robot_id' => $result['robotId']]);
                }
                return response()->json(['html' => $result['previewHtml'], 'screenshot' => $result['previewScreenshot'] ?? null, 'details' => $result['details']]);
            } catch (\Throwable $error) {
                return response()->json(['error' => 'No se pudo abrir la vista de revisión: ' . $error->getMessage()], 502);
            }
        });

        Route::put('/products/{id}/review', function (Request $request, string $id) {
            $data = $request->validate([
                'selectors.name' => ['nullable', 'string', 'max:2000'],
                'selectors.price' => ['nullable', 'string', 'max:2000'],
                'selectors.images' => ['nullable', 'array', 'max:8'],
                'selectors.images.*' => ['string', 'max:2000'],
                'selected.name' => ['nullable', 'string', 'max:500'],
                'selected.price' => ['nullable', 'string', 'max:200'],
                'selected.images' => ['nullable', 'array', 'max:8'],
                'selected.images.*' => ['url', 'max:2000'],
            ]);
            $product = DB::table('products')->where('id', $id)->where('user_id', $request->session()->get('user_id'))->first();
            if (! $product) return response()->json(['error' => 'Producto no encontrado.'], 404);
            $selectors = json_decode($product->selectors_json ?? '', true) ?: [];
            $selectors['name'] = ! empty($data['selectors']['name']) ? $data['selectors']['name'] : ($selectors['name'] ?? '');
            $selectors['price'] = ! empty($data['selectors']['price']) ? $data['selectors']['price'] : ($selectors['price'] ?? '');
            $selectors['images'] = ! empty($data['selectors']['images']) ? $data['selectors']['images'] : ($selectors['images'] ?? []);
            $selectors['manual'] = true;
            $selected = $data['selected'] ?? [];
            if (empty($selected)) return response()->json(['error' => 'Selecciona al menos un dato antes de guardar.'], 422);
            $details = [
                'name' => ! empty($selected['name']) ? $selected['name'] : $product->name,
                'price' => ! empty($selected['price']) ? $selected['price'] : $product->price,
                'images' => ! empty($selected['images']) ? $selected['images'] : (json_decode((string) $product->image, true) ?: array_filter([$product->image])),
            ];
            $details['image'] = $details['images'][0] ?? '';
            $missing = missingProductFields($details, $selectors);
            $now = now()->utc();
            DB::table('products')->where('id', $id)->update([
                'selectors_json' => json_encode($selectors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'name' => $details['name'], 'price' => $details['price'], 'image' => storedProductImages($details),
                'last_checked_at' => $now->toDateTimeString(),
                'next_check_at' => $now->copy()->addMinutes((int) $product->interval_minutes)->toDateTimeString(),
                'error' => $missing ? 'No se detectó ' . implode(', ', $missing) . ' en la página.' : null,
            ]);
            recordProductHistory($id, $details, $now->toDateTimeString());
            return response()->json(radarProduct(DB::table('products')->where('id', $id)->first()));
        });

        Route::post('/products/{id}/refresh', function (Request $request, string $id) {
            $product = DB::table('products')->where('id', $id)->where('user_id', $request->session()->get('user_id'))->first();
            if (! $product) return response()->json(['error' => 'Producto no encontrado.'], 404);
            $maxun = app(MaxunScraper::class);
            if (! $maxun->isConfigured()) {
                return response()->json(['error' => 'Configura MAXUN_API_KEY en backend/.env para conectar el extractor Maxun.'], 503);
            }
            try {
                $robotId = $product->maxun_robot_id ?: $maxun->createRobot($product->id, $product->url);
                $selectors = json_decode($product->selectors_json ?? '', true) ?: [];
                $result = $maxun->runRobot($robotId, $product->url, $selectors);
                $robotId = $result['robotId'] ?? $robotId;
                $details = $result['details'];
                // Keep the image explicitly selected by the user when a
                // refresh returns the same <img> with a temporary/empty src.
                if (empty($details['image']) && ! empty($selectors['images']) && ! empty($product->image)) {
                    $savedImages = json_decode((string) $product->image, true);
                    $details['images'] = is_array($savedImages) ? $savedImages : [$product->image];
                    $details['image'] = $details['images'][0] ?? $product->image;
                }
                $missing = missingProductFields($details, $selectors);
                DB::table('products')->where('id', $id)->where('user_id', $request->session()->get('user_id'))->update([
                    'maxun_robot_id' => $robotId,
                    'name' => $details['name'] ?: $product->name, 'price' => $details['price'] ?: $product->price, 'image' => $details['image'] ? storedProductImages($details) : $product->image,
                    'source' => $details['source'] ?: $product->source,
                    'last_checked_at' => now()->utc()->toDateTimeString(),
                    'next_check_at' => now()->utc()->addMinutes((int) $product->interval_minutes)->toDateTimeString(),
                    'error' => $missing ? 'No se detectó ' . implode(', ', $missing) . ' en la página.' : null,
                ]);
                recordProductHistory($id, $details, now()->utc()->toDateTimeString());
                $updated = radarProduct(DB::table('products')->where('id', $id)->first());
                $updated['extractionStatus'] = $missing ? 'partial' : 'success';
                $updated['robotMessage'] = $missing
                    ? 'Extracción parcial; faltó detectar: ' . implode(', ', $missing) . '.'
                    : 'Producto actualizado correctamente.';
                return response()->json($updated);
            } catch (\Throwable $error) {
                DB::table('products')->where('id', $id)->update(['error' => Str::limit($error->getMessage(), 500)]);
                return response()->json(['error' => 'Maxun no pudo actualizar el producto: ' . $error->getMessage()], 502);
            }
        });

        Route::delete('/products/{id}', function (Request $request, string $id) {
            $deleted = DB::table('products')->where('id', $id)->where('user_id', $request->session()->get('user_id'))->delete();
            return $deleted ? response()->noContent() : response()->json(['error' => 'Producto no encontrado.'], 404);
        });
    });
});

function radarProduct(object $row): array
{
    $iso = static fn ($value) => $value ? gmdate(DATE_ATOM, strtotime($value . ' UTC')) : null;
    $images = json_decode((string) $row->image, true);
    if (! is_array($images)) {
        $images = $row->image ? [$row->image] : [];
    }
    return [
        'id' => $row->id, 'url' => $row->url, 'name' => $row->name,
        'price' => $row->price, 'image' => $images[0] ?? '', 'images' => $images, 'source' => $row->source,
        'intervalMinutes' => (int) $row->interval_minutes,
        'createdAt' => $iso($row->created_at), 'lastCheckedAt' => $iso($row->last_checked_at),
        'nextCheckAt' => $iso($row->next_check_at), 'error' => $row->error ?? '',
    ];
}

function storedProductImages(array $details): string
{
    $images = array_values(array_filter($details['images'] ?? [$details['image'] ?? '']));
    return count($images) > 1
        ? json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : (string) ($images[0] ?? '');
}

function recordProductHistory(string $productId, array $details, string $capturedAt): void
{
    DB::table('product_price_history')->insert([
        'id' => (string) Str::uuid(),
        'product_id' => $productId,
        'name' => (string) ($details['name'] ?? ''),
        'price' => (string) ($details['price'] ?? ''),
        'image' => json_encode($details['images'] ?? array_filter([$details['image'] ?? '']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'captured_at' => $capturedAt,
    ]);
}

function missingProductFields(array $details, array $selectors): array
{
    $fields = [];
    if (empty($selectors['manual']) || ! empty($selectors['name'])) {
        if (($details['name'] ?? '') === '') $fields[] = 'nombre';
    }
    if (empty($selectors['manual']) || ! empty($selectors['price'])) {
        if (($details['price'] ?? '') === '') $fields[] = 'precio';
    }
    if (empty($selectors['manual']) || ! empty($selectors['images'])) {
        if (($details['image'] ?? '') === '') $fields[] = 'imagen';
    }
    return $fields;
}
