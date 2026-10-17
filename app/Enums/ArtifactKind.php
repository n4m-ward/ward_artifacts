<?php

namespace App\Enums;

enum ArtifactKind: string
{
    /** Um único arquivo .html, tudo inline. */
    case Html = 'html';

    /** Um .zip com index.html + assets relativos. */
    case Bundle = 'bundle';
}
