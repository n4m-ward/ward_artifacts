<?php

namespace App\Designs;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\DesignBoard;
use Illuminate\Support\Collection;

/**
 * O HTML de um artboard como ele é mostrado: com os tokens (do design system
 * usado e do próprio design) no :root, e cada <ward-include> trocado pelo
 * corpo do componente (com os <style> e as fontes dele). O arquivo salvo
 * continua com o include.
 */
class BoardRenderer
{
    private const MAX_DEPTH = 3;

    /** @var array<string, ?Artifact> */
    private array $systems = [];

    public function __construct(private DesignStorage $storage, private DesignSystems $designSystems, private ContentGateway $gateway) {}

    public function render(Artifact $design, DesignBoard $board, ?string $html = null): string
    {
        $html ??= $this->storage->html($board);
        $head = [];
        $tokens = [];

        $html = $this->expand($html, $design, $this->components($design, $board), $head, $tokens, [$board->name], 0);

        // Ordem: tokens dos design systems usados, depois os do próprio design (que valem por cima).
        $system = $design->usesDesignSystem;
        if ($system?->design_tokens) {
            $tokens[$system->uuid] = $system->design_tokens;
        }
        if ($design->design_tokens) {
            unset($tokens[$design->uuid]);
            $tokens[$design->uuid] = $design->design_tokens;
        }

        $styles = array_map(fn ($css) => ':root{'.$css.'}', array_values($tokens));
        if ($styles !== []) {
            array_unshift($head, '<style id="ward-tokens">'.implode("\n", $styles).'</style>');
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

        foreach ($board->includes ?? [] as $reference) {
            [$systemRef, $name] = str_contains($reference, ':') ? explode(':', $reference, 2) : [null, $reference];

            if ($systemRef !== null) {
                $system = $this->system($systemRef);
                $parts[] = $name.':'.($system?->boards()->where('name', $name)->value('revision') ?? 0).'.'.substr(md5((string) $system?->design_tokens), 0, 6);
            } else {
                $parts[] = $name.':'.($byName?->get($name)?->revision ?? $design->boards()->where('name', $name)->value('revision') ?? 0);
            }
        }

        $tokens = (string) $design->usesDesignSystem?->design_tokens.'|'.$design->design_tokens;
        if ($tokens !== '|') {
            $parts[] = substr(md5($tokens), 0, 8);
        }

        return implode('.', $parts);
    }

    /**
     * @return Collection<string, DesignBoard>
     */
    private function components(Artifact $design, ?DesignBoard $board = null): Collection
    {
        if ($board !== null && empty($board->includes)) {
            return collect();
        }

        return $design->boards()->get()->keyBy('name');
    }

    private function system(string $reference): ?Artifact
    {
        return $this->systems[$reference] ??= $this->designSystems->find($reference);
    }

    /**
     * @param  Collection<string, DesignBoard>  $components
     * @param  list<string>  $head
     * @param  array<string, string>  $tokens
     * @param  list<string>  $stack
     */
    private function expand(string $html, Artifact $design, Collection $components, array &$head, array &$tokens, array $stack, int $depth): string
    {
        return (string) preg_replace_callback(DesignBoard::INCLUDE_PATTERN, function ($match) use ($design, $components, &$head, &$tokens, $stack, $depth) {
            $systemRef = strtolower($match[2] ?? '');
            $name = $match[4];
            $key = $systemRef !== '' ? "{$systemRef}:{$name}" : $name;
            $missing = '<!-- ward-include: "'.e($key).'" não encontrado -->';

            if (in_array($key, $stack, true) || $depth >= self::MAX_DEPTH) {
                return $missing;
            }

            if ($systemRef !== '') {
                $system = $this->system($systemRef);
                $component = $system?->boards()->where('name', $name)->first();

                if (! $component) {
                    return $missing;
                }

                if ($system->design_tokens && ! isset($tokens[$system->uuid])) {
                    $tokens = [$system->uuid => $system->design_tokens] + $tokens;
                }

                // Imagens do componente continuam vindo do design system dele.
                $base = preg_replace('#/[^/]*$#', '/', $this->gateway->boardUrl($component));
                $source = preg_replace('#(["\'(\s])assets/#', '$1'.$base.'assets/', $this->storage->html($component));
                $source = $this->expand($source, $system, $this->components($system), $head, $tokens, [...$stack, $key], $depth + 1);
            } else {
                $component = $components->get($name);

                if (! $component) {
                    return $missing;
                }

                $source = $this->expand($this->storage->html($component), $design, $components, $head, $tokens, [...$stack, $key], $depth + 1);
            }

            // Estilos e fontes do componente vão para o <head> de quem usa.
            preg_match_all('#<style\b[^>]*>.*?</style>|<link\b[^>]*rel=["\']?(?:stylesheet|preconnect)[^>]*>#is', $this->headOf($source), $assets);
            array_push($head, ...$assets[0]);

            return '<div data-ward-component="'.e($name).'"'.($systemRef !== '' ? ' data-ward-system="'.e($systemRef).'"' : '').' style="display: contents">'.$this->bodyOf($source).'</div>';
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
