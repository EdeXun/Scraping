<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\MaxunScraper;
use Throwable;

class CheckProducts extends Command
{
    protected $signature = 'radar:check-products';
    protected $description = 'Consulta productos cuyo intervalo de revisión ya venció';

    public function handle(): int
    {
        $maxun = app(MaxunScraper::class);
        if (! $maxun->isConfigured()) {
            $this->error('Configura MAXUN_API_KEY en backend/.env antes de ejecutar las extracciones.');
            return self::FAILURE;
        }
        $due = DB::table('products')->where('next_check_at', '<=', now()->utc()->toDateTimeString())
            ->orderBy('next_check_at')->limit(25)->get();

        foreach ($due as $product) {
            $checkedAt = now()->utc();
            $nextCheckAt = $checkedAt->copy()->addMinutes((int) $product->interval_minutes)->toDateTimeString();
            try {
                $robotId = $product->maxun_robot_id ?: $maxun->createRobot($product->id, $product->url);
                $selectors = json_decode($product->selectors_json ?? '', true) ?: [];
                $result = $maxun->runRobot($robotId, $product->url, $selectors);
                $robotId = $result['robotId'] ?? $robotId;
                $details = $result['details'];
                $missing = [];
                if (empty($selectors['manual']) || ! empty($selectors['name'])) {
                    if ($details['name'] === '') $missing[] = 'nombre';
                }
                if (empty($selectors['manual']) || ! empty($selectors['price'])) {
                    if ($details['price'] === '') $missing[] = 'precio';
                }
                if (empty($selectors['manual']) || ! empty($selectors['images'])) {
                    if ($details['image'] === '') $missing[] = 'imagen';
                }
                $error = $missing ? 'No se detectó ' . implode(', ', $missing) . ' en la página.' : null;
                DB::table('products')->where('id', $product->id)->update([
                    'maxun_robot_id' => $robotId,
                    'name' => $details['name'] ?: $product->name,
                    'price' => $details['price'] ?: $product->price,
                    'image' => count($details['images'] ?? []) > 1
                        ? json_encode($details['images'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : ($details['image'] ?: $product->image),
                    'source' => $details['source'] ?: $product->source,
                    'last_checked_at' => $checkedAt->toDateTimeString(),
                    'next_check_at' => $nextCheckAt,
                    'error' => $error,
                ]);
                DB::table('product_price_history')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'product_id' => $product->id,
                    'name' => (string) $details['name'],
                    'price' => (string) $details['price'],
                    'image' => json_encode($details['images'] ?? array_filter([$details['image']]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'captured_at' => $checkedAt->toDateTimeString(),
                ]);
                $this->info("Actualizado: {$product->url}");
            } catch (Throwable $error) {
                DB::table('products')->where('id', $product->id)->update([
                    'maxun_robot_id' => $product->maxun_robot_id,
                    'last_checked_at' => $checkedAt->toDateTimeString(),
                    'next_check_at' => $nextCheckAt,
                    'error' => $error->getMessage(),
                ]);
                $this->error("Error en {$product->url}: {$error->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
