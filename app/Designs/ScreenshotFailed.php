<?php

namespace App\Designs;

use RuntimeException;

/**
 * O print não saiu (sem Chrome configurado, outro print em andamento, tempo
 * esgotado...). A mensagem é para a IA, em português.
 */
class ScreenshotFailed extends RuntimeException {}
