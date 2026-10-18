<?php

namespace App\Designs;

use App\Models\Artifact;
use App\Models\DesignBoard;
use Illuminate\Support\Collection;

/**
 * O HTML de um artboard como ele é mostrado: com os tokens do design no :root
 * e cada <ward-include name="X"> trocado pelo corpo do artboard X (com os
 * <style> e as fontes dele). O arquivo salvo continua com o include.
 */
class BoardRenderer
{
    private const MAX_DEPTH = 3;

    public function __construct(private DesignStorage $storage) {}

    public function render(Artifact $design, DesignBoard $board, ?string $html = null): string
    {
        $html ??= $this->storage->html($board);
        $components = $this->components($design, $board);
        $head = [];

        $html = $this->expand($html, $components, $head, [$board->name], 0);

        if ($design->design_tokens) {
            array_unshift($head, '<style id="ward-tokens">:root{'.$design->design_tokens.'}</style>');
        }

        return $head === [] ? $html : $this->intoHead($html, implode('', array_unique($head)));
    }

    /**
     * Versão para o canvas: muda quando o artboard, um componente que ele usa ou os tokens mudam.
     *
     * @param  Collection<string, DesignBoard>|null  $byName
     */
    public function version(Artifact $design, DesignBoard $board, ?Collection $byName = null): string
    {
        $parts = [(string) $board->revision];

        foreach ($board->includes ?? [] as $name) {
            $parts[] = $name.':'.($byName?->get($name)?->revision ?? $design->boards()->where('name', $name)->value('revision') ?? 0);
        }

        if ($design->design_tokens) {
            $parts[] = substr(md5($design->design_tokens), 0, 8);
        }

        return implode('.', $parts);
    }

    /**
     * @return Collection<string, DesignBoard>
     */
    private function components(Artifact $design, DesignBoard $board): Collection
    {
        if (empty($board->includes)) {
            return collect();
        }

        return $design->boards()->get()->keyBy('name');
    }

    /**
     * @param  Collection<string, DesignBoard>  $components
     * @param  list<string>  $head
     * @param  list<string>  $stack
     */
    private function expand(string $html, Collection $components, array &$head, array $stack, int $depth): string
    {
        return (string) preg_replace_callback(DesignBoard::INCLUDE_PATTERN, function ($match) use ($components, &$head, $stack, $depth) {
            $name = $match[2];
            $component = $components->get($name);

            if (! $component || in_array($name, $stack, true) || $depth >= self::MAX_DEPTH) {
                return '<!-- ward-include: "'.e($name).'" não encontrado -->';
            }

            $source = $this->storage->html($component);
            $source = $this->expand($source, $components, $head, [...$stack, $name], $depth + 1);

            // Estilos e fontes do componente vão para o <head> de quem usa.
            preg_match_all('#<style\b[^>]*>.*?</style>|<link\b[^>]*rel=["\']?(?:stylesheet|preconnect)[^>]*>#is', $this->headOf($source), $assets);
            array_push($head, ...$assets[0]);

            return '<div data-ward-component="'.e($name).'" style="display: contents">'.$this->bodyOf($source).'</div>';
        }, $html);
    }

    private function headOf(string $html): string
    {
        return preg_match('#<head\b[^>]*>(.*?)</head>#is', $html, $match) ? $match[1] : $html;
    }

    private function bodyOf(string $html): string
    {
        if (preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $match)) {
            return $match[1];
        }

        // Fragmento sem <body>: tira o que é de <head>.
        return (string) preg_replace('#<!doctype[^>]*>|<html\b[^>]*>|</html>|<head\b[^>]*>.*?</head>#is', '', $html);
    }

    private function intoHead(string $html, string $markup): string
    {
        if (preg_match('/<head(\s[^>]*)?>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $position = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $position).$markup.substr($html, $position);
        }

        if (preg_match('/^\s*<!doctype[^>]*>/i', $html, $match)) {
            return $match[0].$markup.substr($html, strlen($match[0]));
        }

        return $markup.$html;
    }
}
