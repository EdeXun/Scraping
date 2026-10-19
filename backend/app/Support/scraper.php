<?php
declare(strict_types=1);

/** @return list<array<string, mixed>> */
function product_jsonld_candidates(mixed $node): array
{
    if (!is_array($node)) {
        return [];
    }

    $candidates = [];
    $types = (array) ($node['@type'] ?? []);
    foreach ($types as $type) {
        if (stripos((string) $type, 'Product') !== false) {
            $candidates[] = $node;
            break;
        }
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $candidates = array_merge($candidates, product_jsonld_candidates($child));
        }
    }

    return $candidates;
}

function scrape_product(string $url): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; RadarPriceTracker/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $html = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($html) || $status >= 400) {
        throw new RuntimeException($curlError ?: "La tienda respondió con HTTP {$status}.");
    }

    return scrape_product_html($html, $effectiveUrl);
}

function scrape_product_html(string $html, string $effectiveUrl, array $selectors = []): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $meta = static function (string $property) use ($xpath): string {
        $nodes = $xpath->query("//meta[@property='{$property}' or @name='{$property}']/@content");
        return $nodes && $nodes->length ? trim($nodes->item(0)->nodeValue) : '';
    };
    $text = static function (string $query) use ($xpath): string {
        $nodes = $xpath->query($query);
        return $nodes && $nodes->length ? trim(preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent) ?: '') : '';
    };

    $candidates = [];
    foreach ($xpath->query("//script[@type='application/ld+json']") as $script) {
        $decoded = json_decode($script->textContent, true);
        $candidates = array_merge($candidates, product_jsonld_candidates($decoded));
    }
    usort($candidates, static function (array $a, array $b): int {
        return product_jsonld_score($b) <=> product_jsonld_score($a);
    });
    $structured = $candidates[0] ?? [];
    $offers = $structured['offers'] ?? [];
    if (array_is_list($offers)) {
        $offers = $offers[0] ?? [];
    }
    if (!is_array($offers)) {
        $offers = [];
    }

    $host = strtolower((string) (parse_url($effectiveUrl, PHP_URL_HOST) ?: ''));
    $name = trim((string) ($structured['name'] ?? ''));
    $description = trim(preg_replace('/\s+/u', ' ', (string) ($structured['description'] ?? '')) ?: '');
    $image = product_image_url($structured['image'] ?? '');
    if (is_generic_product_name($name, $host)) {
        $image = '';
    }
    $amount = (string) ($offers['price'] ?? $offers['lowPrice'] ?? '');
    $currency = (string) ($offers['priceCurrency'] ?? $meta('product:price:currency'));

    $selectedName = selected_xpath_value($xpath, $selectors['name'] ?? null);
    $selectedPrice = selected_xpath_value($xpath, $selectors['price'] ?? null);
    $selectedImages = [];
    foreach (($selectors['images'] ?? []) as $imageSelector) {
        $selectedImage = selected_xpath_value($xpath, $imageSelector, true);
        if ($selectedImage !== '' && ! in_array($selectedImage, $selectedImages, true)) {
            $selectedImages[] = $selectedImage;
        }
    }

    // Prefer store-specific product selectors over site-wide Open Graph branding.
    if (str_contains($host, 'mercadolibre')) {
        $name = $text("//*[contains(@class,'ui-pdp-title')]") ?: $name;
        $image = product_image_url($image) ?: product_image_url($xpath->evaluate("string((//*[contains(@class,'ui-pdp-gallery') or contains(@class,'ui-pdp-image')])[1]//img/@src)"));
        $amount = $amount ?: $text("//*[contains(@class,'ui-pdp-price__second-line')]//*[contains(@class,'andes-money-amount__fraction')]");
        if ($amount !== '' && !preg_match('/\d/', $amount)) {
            $amount = '';
        }
    } elseif (str_contains($host, 'amazon.')) {
        $name = $text("//*[@id='productTitle']") ?: $name;
        $image = product_image_url($image) ?: product_image_url($xpath->evaluate("string(//*[@id='landingImage']/@src | //*[@id='imgTagWrapperId']//img/@src)"));
        $amount = $amount ?: $text("//*[@id='corePrice_feature_div']//*[contains(@class,'a-offscreen')]");
        $amount = $amount ?: $text("//*[@id='priceblock_ourprice' or @id='priceblock_dealprice']");
    }

    $name = $selectedName ?: $name;
    $amount = $selectedPrice ?: $amount;
    $image = $selectedImages[0] ?? $image;
    $name = $name ?: $meta('og:title') ?: $meta('twitter:title');
    if ($name === '' || is_generic_product_name($name, $host)) {
        $name = $text('//h1');
    }
    if ($name === '' || is_generic_product_name($name, $host)) {
        $name = $text("//h1[contains(@class,'product') or contains(@class,'title')]");
    }
    if ($name === '' || is_generic_product_name($name, $host)) {
        $name = $text('//title');
    }
    $description = $description ?: $meta('og:description') ?: $meta('description') ?: $meta('twitter:description');
    if ($description === '') {
        $description = $text("//*[@itemprop='description']") ?: $text("//*[contains(@class,'description') or contains(@class,'Description')]");
    }

    $amount = $amount ?: $meta('product:price:amount') ?: $meta('og:price:amount');
    if ($amount === '') {
        $amount = $text("//*[@itemprop='price']/@content | //*[@itemprop='price']");
    }
    $price = $amount !== '' ? trim(($currency !== '' ? $currency . ' ' : '') . $amount) : '';

    if ($image === '' && !str_contains($host, 'mercadolibre') && !str_contains($host, 'amazon.')) {
        $image = product_image_url($meta('og:image')) ?: product_image_url($meta('twitter:image'));
    }
    if ($image !== '' && !preg_match('~^https?://~i', $image)) {
        $parts = parse_url($effectiveUrl);
        $image = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . '/' . ltrim($image, '/');
    }

    $bodyText = strtolower($text('//body'));
    if (($name === '' || is_generic_product_name($name, $host)) && looks_like_access_wall($bodyText, $effectiveUrl)) {
        throw new RuntimeException('La tienda mostró una pantalla de inicio de sesión, verificación o bloqueo; no se pudo acceder a la ficha del producto.');
    }
    if (is_generic_product_name($name, $host)) {
        $name = '';
    }

    $images = $selectedImages ?: ($image !== '' ? [$image] : []);
    if (! empty($selectors['manual'])) {
        $name = $selectedName;
        $amount = $selectedPrice;
        $images = $selectedImages;
        $image = $images[0] ?? '';
        $price = $amount;
    }

    return ['name' => $name, 'description' => $description, 'price' => $price, 'image' => $image, 'images' => $images, 'source' => (string) (parse_url($effectiveUrl, PHP_URL_HOST) ?: '')];
}

function selected_xpath_value(DOMXPath $xpath, mixed $selector, bool $image = false): string
{
    if (! is_string($selector) || $selector === '' || strlen($selector) > 2000) {
        return '';
    }
    $nodes = @$xpath->query($selector);
    if (! $nodes || $nodes->length === 0) {
        return '';
    }
    $node = $nodes->item(0);
    if ($node instanceof DOMAttr) {
        $value = trim($node->value);
    } elseif ($image) {
        $value = '';
        // Shops often keep a placeholder in src and put the real product
        // image in a lazy-loading attribute. Prefer those actual candidates.
        foreach (['data-src', 'data-original', 'data-lazy-src', 'src', 'srcset'] as $attribute) {
            $candidate = trim($node->attributes?->getNamedItem($attribute)?->nodeValue ?? '');
            if ($attribute === 'srcset' && $candidate !== '') {
                $candidate = preg_split('/\s+/', trim(explode(',', $candidate)[0]))[0] ?? '';
            }
            if (preg_match('~^https?://~i', $candidate) && product_image_url($candidate) !== '') {
                $value = $candidate;
                break;
            }
        }
    } else {
        $value = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?: '');
    }
    if ($image && $value !== '' && ! preg_match('~^https?://~i', $value)) {
        $value = '';
    }
    return $image ? product_image_url($value) : $value;
}

function sanitize_preview_html(string $html, string $effectiveUrl): string
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    foreach (iterator_to_array($xpath->query('//script|//iframe|//frame|//object|//embed|//form|//input|//button|//textarea|//select|//meta[@http-equiv]')) as $node) {
        $node->parentNode?->removeChild($node);
    }
    // VTEX (used by Elektra) stores its fallback stylesheets inside an inert
    // <template id="stylesFallback">. Browsers do not load styles from a
    // template, so promote those links into <head> for the selectable preview.
    $head = $dom->getElementsByTagName('head')->item(0);
    if ($head) {
        $fallbackStylesheets = $xpath->query('//template[@id="stylesFallback"]//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")]');
        foreach (iterator_to_array($fallbackStylesheets) as $stylesheet) {
            $stylesheet->removeAttribute('crossorigin');
            $head->appendChild($stylesheet);
        }
    }
    // Some third-party shadow widgets are serialized as visible text by the
    // scraper instead of as a <style> element. Remove only that CSS payload.
    foreach (iterator_to_array($xpath->query('//span[starts-with(normalize-space(.), ":host{--syndiDefaultFontFamily")]')) as $styleText) {
        $styleText->parentNode?->removeChild($styleText);
    }
    foreach ($xpath->query('//*[@*]') as $element) {
        if (! $element->hasAttributes()) {
            continue;
        }
        if (in_array('vtex-sticky-layout-0-x-wrapper', preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true)) {
            $inlineStyle = $element->getAttribute('style');
            $inlineStyle = preg_replace('/(^|;)\s*height\s*:[^;]*(;|$)/i', '$1', $inlineStyle) ?: '';
            $inlineStyle = trim($inlineStyle, " ;\t\n\r\0\x0B");
            if ($inlineStyle === '') {
                $element->removeAttribute('style');
            } else {
                $element->setAttribute('style', $inlineStyle);
            }
        }
        if (strtolower($element->tagName) === 'link'
            && preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $element->getAttribute('rel'))) {
            $element->removeAttribute('crossorigin');
        }
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributeName = strtolower($attribute->name);
            if (str_starts_with($attributeName, 'on') || $attributeName === 'srcdoc') {
                $element->removeAttribute($attribute->name);
            } elseif (in_array($attributeName, ['href', 'src', 'action', 'xlink:href'], true) && preg_match('/^\s*(javascript|vbscript|data):/i', $attribute->value)) {
                $element->removeAttribute($attribute->name);
            }
        }
        // Many commerce pages lazy-load product media. Promote common lazy
        // attributes so the captured preview can display images without JS.
        if (strtolower($element->tagName) === 'img') {
            foreach (['data-src', 'data-original', 'data-lazy-src'] as $lazyAttribute) {
                $candidate = trim($element->getAttribute($lazyAttribute));
                if ($candidate !== '' && ! preg_match('/^\s*(javascript|vbscript|data):/i', $candidate)) {
                    if (! $element->hasAttribute('src') || str_starts_with($element->getAttribute('src'), 'data:image')) {
                        $element->setAttribute('src', $candidate);
                    }
                    break;
                }
            }
        }
        if (strtolower($element->tagName) === 'a') {
            $element->removeAttribute('href');
        }
    }

    $head = $dom->getElementsByTagName('head')->item(0);
    if (! $head) {
        $head = $dom->createElement('head');
        $dom->documentElement?->insertBefore($head, $dom->documentElement->firstChild);
    }
    $base = $dom->createElement('base');
    $base->setAttribute('href', $effectiveUrl);
    $head->insertBefore($base, $head->firstChild);
    $policy = $dom->createElement('meta');
    $policy->setAttribute('http-equiv', 'Content-Security-Policy');
    $policy->setAttribute('content', "default-src 'none'; base-uri https:; img-src https: data:; style-src 'unsafe-inline' https:; font-src https: data:; media-src https: data:");
    $head->insertBefore($policy, $base->nextSibling);
    return $dom->saveHTML() ?: '';
}

function product_jsonld_score(array $product): int
{
    $score = 0;
    $name = trim((string) ($product['name'] ?? ''));
    if ($name !== '' && !is_generic_product_name($name, '')) {
        $score += 5;
    }
    if (!empty($product['image'])) {
        $score += 3;
    }
    if (!empty($product['offers'])) {
        $score += 4;
    }
    return $score;
}

function product_image_url(mixed $image): string
{
    if (is_array($image)) {
        $image = $image['url'] ?? $image['contentUrl'] ?? $image[0] ?? '';
    }
    if (!is_string($image)) {
        return '';
    }
    $image = trim($image);
    if ($image === '' || preg_match('/logo|sprite|favicon|icon|avatar|banner|placeholder|pixel/i', $image)) {
        return '';
    }
    return $image;
}

function is_generic_product_name(string $name, string $host): bool
{
    $normalized = mb_strtolower(trim($name));
    $normalized = preg_replace('/\s*[|–—-]\s*.*$/u', '', $normalized) ?: $normalized;
    $hostBrand = preg_replace('/^www\./', '', $host) ?: $host;
    $hostBrand = preg_replace('/\.(com|mx|net|org)(\.[a-z]{2})?$/', '', $hostBrand) ?: $hostBrand;
    $hostBrand = str_replace(['mercadolibre', 'amazon'], ['mercado libre', 'amazon'], $hostBrand);
    $generic = ['mercado libre', 'amazon', 'temu', 'iniciar sesión/registrarse', 'iniciar sesión', 'sign in', 'welcome to temu'];
    if (in_array($normalized, $generic, true)) {
        return true;
    }
    return $hostBrand !== '' && $normalized === mb_strtolower($hostBrand);
}

function looks_like_access_wall(string $bodyText, string $url): bool
{
    $signals = ['inicia sesión para continuar', 'iniciar sesión o registrarse', 'verify you are human', 'captcha', 'access denied', 'robot or human', 'demasiadas solicitudes', 'unusual traffic'];
    foreach ($signals as $signal) {
        if (str_contains($bodyText, $signal)) {
            return true;
        }
    }
    return (bool) preg_match('~/(login|signin|sign-in|captcha|verify)(/|\?|$)~i', $url);
}
