<?php

use App\Http\Controllers\Api\V1\SuperAdmin\BusinessesController;
use App\Http\Controllers\Api\V1\SuperAdmin\DashboardController;
use App\Http\Controllers\Api\V1\SuperAdmin\PaymentMethodCatalogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de plataforma
|--------------------------------------------------------------------------
|
| Se incluyen desde api.php dentro de un grupo auth:sanctum + superadmin ya
| montado en /api/v1/superadmin. En archivo aparte, igual que el POS, porque
| son rutas que cruzan todos los tenants y conviene que se vean de un vistazo
| separadas del resto -- no perdidas entre las del negocio.
*/

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/feature-catalog', [BusinessesController::class, 'featureCatalog'])->name('feature-catalog');

Route::get('/businesses', [BusinessesController::class, 'index'])->name('businesses.index');
Route::post('/businesses', [BusinessesController::class, 'store'])->name('businesses.store');
Route::get('/businesses/{business}', [BusinessesController::class, 'show'])->name('businesses.show');
Route::patch('/businesses/{business}', [BusinessesController::class, 'update'])->name('businesses.update');
Route::patch('/businesses/{business}/toggle', [BusinessesController::class, 'toggle'])->name('businesses.toggle');

// Catalogo global de medios de pago. Cada negocio elige de aca cuales usa.
Route::get('/payment-methods', [PaymentMethodCatalogController::class, 'index'])->name('payment-methods.index');
Route::post('/payment-methods', [PaymentMethodCatalogController::class, 'store'])->name('payment-methods.store');
Route::patch('/payment-methods/{method}', [PaymentMethodCatalogController::class, 'update'])->name('payment-methods.update');
