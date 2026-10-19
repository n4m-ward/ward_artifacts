<?php

namespace App\Designs;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

/**
 * Acha um elemento no HTML de um artboard pelo texto visível ("Pagar") ou por
 * seletor CSS ("#pagar", "nav a:last-child"), do mesmo jeito que o runtime do
 * canvas acha (resources/design/runtime.js), e diz para onde ele leva no Play.
 */
class BoardElementFinder
{
    /** Primeiro os elementos clicáveis; o texto mais curto ganha (o mais específico). */
    private const CLICKABLE = '//a|//button|//*[@data-ward-to]|//*[@data-ward-overlay]|//*[@data-ward-back]|//*[@data-ward-close]|//*[@role="button"]|//*[@role="tab"]|//input|//summary|//label|//li';

    /**
     * @return array{tag: string, text: string, link: array{to?: string, overlay?: bool, back?: bool, close?: bool}|null}|null
     */
    public function find(string $html, string $element): ?array
    {
        $element = trim($element);
        if ($element === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        // Texto primeiro ("Pagar"); se nenhum texto bate, tenta como seletor ("#pagar").
        $node = $this->byText($xpath, $element) ?? $this->bySelector($xpath, $element);

        if (! $node) {
            return null;
        }

        return [
            'tag' => strtolower($node->tagName),
            'text' => mb_substr(static::text($node), 0, 80),
            'link' => $this->linkOf($node),
        ];
    }

    private function bySelector(DOMXPath $xpath, string $selector): ?DOMElement
    {
        try {
            $nodes = $xpath->query((new CssSelectorConverter)->toXPath($selector));
        } catch (Throwable) {
            return null;
        }

        return $nodes && $nodes->length && $nodes->item(0) instanceof DOMElement ? $nodes->item(0) : null;
    }

    private function byText(DOMXPath $xpath, string $text): ?DOMElement
    {
        $needle = mb_strtolower($text);

        foreach ([self::CLICKABLE, '//body//*'] as $query) {
            $best = null;
            $bestLength = PHP_INT_MAX;

            foreach ($xpath->query($query) ?: [] as $node) {
                if (! $node instanceof DOMElement || in_array(strtolower($node->tagName), ['script', 'style', 'head', 'title'], true)) {
                    continue;
                }
                $content = static::text($node);
                $label = mb_strtolower($content.' '.$node->getAttribute('aria-label').' '.$node->getAttribute('value'));
                if (str_contains($label, $needle) && mb_strlen($content) < $bestLength) {
                    $best = $node;
                    $bestLength = mb_strlen($content);
                }
            }

            if ($best) {
                return $best;
            }
        }

        return null;
    }

    /**
     * @return array{to?: string, overlay?: bool, back?: bool, close?: bool}|null
     */
    private function linkOf(DOMElement $node): ?array
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            if ($current->hasAttribute('data-ward-close')) {
                return ['close' => true];
            }
            if ($current->hasAttribute('data-ward-back')) {
                return ['back' => true];
            }
            if ($current->hasAttribute('data-ward-overlay')) {
                return ['to' => trim($current->getAttribute('data-ward-overlay')), 'overlay' => true];
            }
            if ($current->hasAttribute('data-ward-to')) {
                return ['to' => trim($current->getAttribute('data-ward-to'))];
            }
            $href = $current->getAttribute('href');
            if (str_starts_with($href, 'board:')) {
                return ['to' => trim(rawurldecode(substr($href, 6)))];
            }
        }

        return null;
    }

    private static function text(DOMElement $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
