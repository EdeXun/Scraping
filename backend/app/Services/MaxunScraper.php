<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MaxunScraper
{
    public function isConfigured(): bool
    {
        return filled(config('services.maxun.api_key'));
    }

    public function createRobot(string $productId, string $url): string
    {
        $this->ensureConfigured();
        $response = $this->client()->post($this->endpoint('/sdk/robots'), [
            'meta' => [
                'name' => 'Radar ' . substr($productId, 0, 8),
                'type' => 'scrape',
                'url' => $url,
                'formats' => ['html'],
            ],
            'workflow' => [],
        ]);

        $payload = $response->json();
        if (! $response->successful()) {
            throw new RuntimeException($this->responseError($payload, 'Maxun no pudo crear el robot.'));
        }

        $robotId = data_get($payload, 'data.recording_meta.id') ?: data_get($payload, 'data.id');
        if (! is_string($robotId) || $robotId === '') {
            throw new RuntimeException('Maxun creó una respuesta sin identificador de robot.');
        }

        return $robotId;
    }

    public function runRobot(string $robotId, string $url, array $selectors = [], bool $includePreview = false): array
    {
        // Maxun can need more than PHP's default 30 seconds to finish a scrape.
        set_time_limit(300);
        $this->ensureConfigured();
        require_once base_path('app/Support/scraper.php');
        $response = $this->executeRobot($robotId, $includePreview);
        $payload = $response->json();
        if (! $response->successful() && str_contains(strtolower($this->responseError($payload, '')), 'failed to start robot execution')) {
            $metadataId = $this->resolveRobotMetadataId($robotId);
            if ($metadataId !== null && $metadataId !== $robotId) {
                $robotId = $metadataId;
                $response = $this->executeRobot($robotId, $includePreview);
                $payload = $response->json();
            }
        }
        if (! $response->successful()) {
            throw new RuntimeException($this->responseError($payload, 'Maxun no pudo ejecutar el robot.'));
        }

        $html = data_get($payload, 'data.data.html');
        if (! is_string($html) || trim($html) === '') {
            throw new RuntimeException('Maxun terminó la ejecución, pero no devolvió el HTML de la página.');
        }

        $details = scrape_product_html($html, $url, $selectors);
        if (empty($selectors['manual'])) {
            $details = $this->fillMissingDetailsFromMarkdown($details, data_get($payload, 'data.data.markdown'));
        }
        if (! $includePreview && $details['name'] === '' && $details['price'] === '' && $details['image'] === '') {
            throw new RuntimeException('El robot abrió la página, pero no encontró nombre, precio ni imagen del producto.');
        }

        $result = [
            'details' => $details,
            'runId' => data_get($payload, 'data.runId'),
            'robotId' => $robotId,
        ];
        if ($includePreview) {
            $result['previewHtml'] = sanitize_preview_html($html, $url);
            $screenshot = data_get($payload, 'run.screenshots.0')
                ?: data_get($payload, 'data.run.screenshots.0')
                ?: data_get($payload, 'data.interpretationInfo.binaryOutput.screenshot-visible')
                ?: data_get($payload, 'data.binaryOutput.screenshot-visible');
            if (is_string($screenshot) && preg_match('~^https?://~i', $screenshot)) {
                $result['previewScreenshot'] = $screenshot;
            } elseif (is_array($screenshot) && ! empty($screenshot['data'])) {
                $mimeType = $screenshot['mimeType'] ?? 'image/png';
                $result['previewScreenshot'] = str_starts_with($screenshot['data'], 'data:')
                    ? $screenshot['data']
                    : 'data:' . $mimeType . ';base64,' . $screenshot['data'];
            }
        }
        return $result;
    }

    public function deleteRobot(string $robotId): void
    {
        $this->client()->delete($this->endpoint('/sdk/robots/' . rawurlencode($robotId)));
    }

    private function executeRobot(string $robotId, bool $includeScreenshot = false)
    {
        return $this->client()->post($this->endpoint('/sdk/robots/' . rawurlencode($robotId) . '/execute'), [
            'formats' => $includeScreenshot ? ['html', 'markdown', 'screenshot-visible'] : ['html', 'markdown'],
        ]);
    }

    private function fillMissingDetailsFromMarkdown(array $details, mixed $markdown): array
    {
        if (! is_string($markdown) || $markdown === '') {
            return $details;
        }

        if ($details['image'] === '' && preg_match_all('/!\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/i', $markdown, $imageMatches, PREG_SET_ORDER)) {
            foreach ($imageMatches as $imageMatch) {
                $candidate = trim($imageMatch[2]);
                $description = strtolower(trim($imageMatch[1]));
                if (preg_match('/logo|sprite|favicon|icon|avatar|banner|placeholder|prime|mercado.?libre/i', $candidate . ' ' . $description)) {
                    continue;
                }
                $details['image'] = $candidate;
                break;
            }
        }

        if ($details['price'] === '') {
            foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
                if (preg_match('/(?:MXN|USD|CAD|EUR|\$|€|£)\s?\d[\d,.]*|\d{1,3}(?:,\d{3})+(?:\.\d{2})?/iu', $line, $priceMatch)
                    && !preg_match('/shipping|envío|ahorra|descuento|opiniones|reviews|related|recommended|recomendados|prime/i', $line)) {
                    $details['price'] = trim($priceMatch[0]);
                    break;
                }
            }
        }

        return $details;
    }

    private function resolveRobotMetadataId(string $robotId): ?string
    {
        $response = $this->client()->get($this->endpoint('/sdk/robots'));
        if (! $response->successful()) {
            return null;
        }

        $robots = $response->json('data');
        if (! is_array($robots)) {
            return null;
        }

        foreach ($robots as $robot) {
            if ((string) data_get($robot, 'id') === $robotId) {
                $metadataId = data_get($robot, 'recording_meta.id');
                return is_string($metadataId) && $metadataId !== '' ? $metadataId : null;
            }
        }

        return null;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Falta MAXUN_API_KEY en backend/.env. Genera o copia la clave desde Maxun > Clave API.');
        }
    }

    private function client()
    {
        return Http::acceptJson()
            ->withHeaders(['X-API-Key' => config('services.maxun.api_key')])
            ->connectTimeout(10)
            ->timeout(240);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.maxun.url'), '/') . $path;
    }

    private function responseError(mixed $payload, string $fallback): string
    {
        $message = data_get($payload, 'message') ?: data_get($payload, 'error');
        return is_string($message) && $message !== '' ? 'Maxun: ' . $message : $fallback;
    }
}
