<?php

namespace App\Enums;

enum BoardKind: string
{
    /** Tamanho fixo w×h: post, tela de app, pôster. */
    case Fixed = 'fixed';

    /** Página fluida (site, dashboard); w é a largura de referência. */
    case Page = 'page';
}
