<?php

namespace App\Whiteboards;

use RuntimeException;

/**
 * Pedido recusado num whiteboard. A mensagem volta para a pessoa ou para a IA,
 * então diz o que corrigir.
 */
class InvalidWhiteboard extends RuntimeException {}
