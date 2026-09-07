<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * No hay llave publica configurada en este ambiente: el SSO no esta
 * habilitado. Se traduce a 503 y no a 401 porque no es culpa de quien
 * llama, y porque distinguirlo es lo que permite apagar el SSO sin apagar
 * el login propio de esta API (vaciar NEXOLU_AUTH_PUBLIC_KEYS es el
 * interruptor).
 */
class SsoNotConfigured extends RuntimeException {}
