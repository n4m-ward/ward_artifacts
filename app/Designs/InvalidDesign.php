<?php

namespace App\Designs;

use RuntimeException;

/**
 * Pedido recusado num design. A mensagem volta para a IA, então diz o que corrigir.
 */
class InvalidDesign extends RuntimeException {}
