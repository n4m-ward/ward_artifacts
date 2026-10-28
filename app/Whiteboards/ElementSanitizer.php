<?php

namespace App\Whiteboards;

/**
 * Confere um elemento do Excalidraw vindo do navegador ou da IA antes de gravar:
 * tipos e campos conhecidos, números dentro de limites, textos e traços com
 * tamanho máximo, links só http(s). Nada de HTML: o Excalidraw desenha tudo
 * num <canvas>, e o que não passa aqui é recusado com uma mensagem clara.
 */
class ElementSanitizer
{
    public const TYPES = ['rectangle', 'diamond', 'ellipse', 'arrow', 'line', 'freedraw', 'text', 'image', 'frame', 'magicframe', 'embeddable'];

    public const MAX_TEXT = 20_000;

    public const MAX_POINTS = 10_000;

    public const MAX_ELEMENT_BYTES = 512 * 1024;

    private const COORD = 10_000_000;

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public function clean(array $element): array
    {
        $id = (string) ($element['id'] ?? '');

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new InvalidWhiteboard('Elemento com id inválido (use letras, números, "_" e "-", até 64).');
        }

        $type = (string) ($element['type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidWhiteboard("Tipo de elemento não suportado: \"{$type}\" ({$id}).");
        }

        if (! is_int($element['version'] ?? null) || $element['version'] < 1 || $element['version'] > 1_000_000_000) {
            throw new InvalidWhiteboard("Elemento {$id} sem version válida.");
        }

        if (! is_int($element['versionNonce'] ?? null)) {
            throw new InvalidWhiteboard("Elemento {$id} sem versionNonce.");
        }

        foreach (['x', 'y'] as $key) {
            $element[$key] = $this->number($element[$key] ?? 0, -self::COORD, self::COORD);
        }

        foreach (['width', 'height'] as $key) {
            $element[$key] = $this->number($element[$key] ?? 0, -self::COORD, self::COORD);
        }

        $element['isDeleted'] = (bool) ($element['isDeleted'] ?? false);

        foreach (['text', 'originalText'] as $key) {
            if (isset($element[$key])) {
                $element[$key] = mb_substr((string) $element[$key], 0, self::MAX_TEXT);
            }
        }

        if (isset($element['points'])) {
            if (! is_array($element['points']) || count($element['points']) > self::MAX_POINTS) {
                throw new InvalidWhiteboard("Elemento {$id} com pontos demais (máximo ".self::MAX_POINTS.').');
            }

            $element['points'] = array_values(array_map(
                fn ($point) => [$this->number($point[0] ?? 0, -self::COORD, self::COORD), $this->number($point[1] ?? 0, -self::COORD, self::COORD)],
                array_filter($element['points'], 'is_array'),
            ));
        }

        if (isset($element['pressures']) && (! is_array($element['pressures']) || count($element['pressures']) > self::MAX_POINTS)) {
            $element['pressures'] = [];
        }

        $element['link'] = $this->link($element['link'] ?? null, $type);

        if (isset($element['customData']) && (! is_array($element['customData']) || strlen((string) json_encode($element['customData'])) > 4096)) {
            unset($element['customData']);
        }

        if (strlen((string) json_encode($element)) > self::MAX_ELEMENT_BYTES) {
            throw new InvalidWhiteboard("Elemento {$id} grande demais.");
        }

        return $element;
    }

    /**
     * Link de um elemento: só http(s). Num embeddable, só o próprio app
     * (artboards do Ward Design) e os domínios liberados em ward.whiteboards.embed_hosts.
     */
    private function link(mixed $link, string $type): ?string
    {
        if (! is_string($link) || $link === '') {
            return null;
        }

        $link = mb_substr(trim($link), 0, 2000);

        if (! preg_match('#^https?://#i', $link)) {
            return null;
        }

        if ($type !== 'embeddable') {
            return $link;
        }

        return self::embeddable($link) ? $link : null;
    }

    public static function embeddable(string $link): bool
    {
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if ($host === $appHost) {
            return true;
        }

        foreach ((array) config('ward.whiteboards.embed_hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private function number(mixed $value, float $min, float $max): float|int
    {
        if (! is_int($value) && ! is_float($value)) {
            $value = is_numeric($value) ? (float) $value : 0;
        }

        if (is_float($value) && ! is_finite($value)) {
            return 0;
        }

        return max($min, min($max, $value));
    }
}
