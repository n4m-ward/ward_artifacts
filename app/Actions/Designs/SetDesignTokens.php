<?php

namespace App\Actions\Designs;

use App\Designs\InvalidDesign;
use App\Models\Artifact;

/**
 * Tokens do design: um bloco de variáveis CSS (ex.: --ink: #1c1917;) que entra
 * no :root de todos os artboards. Mudou aqui, muda em todas as telas.
 */
class SetDesignTokens extends DesignMutation
{
    public const MAX_BYTES = 20000;

    public function handle(Artifact $design, string $css): void
    {
        $css = trim($css);

        if (strlen($css) > self::MAX_BYTES) {
            throw new InvalidDesign('Os tokens passam de 20 KB.');
        }

        if (preg_match('#</?\s*style|<\s*script#i', $css)) {
            throw new InvalidDesign('Os tokens são só declarações CSS (sem <style> ou <script>).');
        }

        $this->mutate($design, fn () => $design->forceFill(['design_tokens' => $css === '' ? null : $css])->save());
    }

    /**
     * Pares nome → valor das variáveis (--nome: valor;), na ordem em que aparecem.
     *
     * @return array<string, string>
     */
    public static function parse(?string $css): array
    {
        preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;}]+);?/', (string) $css, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn ($match) => [$match[1] => trim($match[2])])->all();
    }
}
