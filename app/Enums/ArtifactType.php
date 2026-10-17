<?php

namespace App\Enums;

enum ArtifactType: string
{
    /** Uma página HTML (ou bundle) com versões. */
    case Page = 'page';

    /** Um canvas de design com artboards (Ward Design). */
    case Design = 'design';
}
