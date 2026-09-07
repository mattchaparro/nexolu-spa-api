<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * La asercion no es valida: firma, emisor, audiencia, tipo, vigencia o
 * reuso. Se traduce a 401 -- el cliente puede pedir una nueva a
 * nexolu-auth y volver a intentar.
 */
class InvalidAssertion extends RuntimeException {}
