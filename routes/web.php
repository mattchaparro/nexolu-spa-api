<?php

use Illuminate\Support\Facades\Route;

// API pura: la unica ruta web es el healthcheck que registra bootstrap/app.php.
Route::get('/', fn () => response()->json(['service' => 'nexolu-spa-api']));
