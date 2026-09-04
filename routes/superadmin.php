<?php

use App\Http\Controllers\Api\V1\SuperAdmin\BusinessesController;
use App\Http\Controllers\Api\V1\SuperAdmin\DashboardController;
use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonateController;
use App\Http\Controllers\Api\V1\SuperAdmin\PaymentMethodCatalogController;
use App\Http\Controllers\Api\V1\SuperAdmin\SocialAccountController;
use App\Http\Controllers\Api\V1\SuperAdmin\WorkflowController;
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

/*
| Flujos de etapas de las citas. Mismo criterio que los medios de pago: el
| catalogo lo mantiene la plataforma y cada negocio elige uno. Cada etapa
| apunta a un estado nucleo del que dependen la agenda, la caja y la nomina,
| asi que dejar que cada negocio los invente seria dejarlo descuadrar su
| propia plata desde una pantalla de configuracion.
*/
Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
Route::post('/workflows', [WorkflowController::class, 'store'])->name('workflows.store');
Route::patch('/workflows/{workflow}', [WorkflowController::class, 'update'])->name('workflows.update');
Route::put('/workflows/{workflow}/stages', [WorkflowController::class, 'saveStages'])->name('workflows.stages');

/*
| "Entrar como" un usuario de un negocio, para soporte. No hay endpoint para
| volver: el front guarda el token del superadmin aparte y salir es un
| POST /logout normal con el token de impersonacion, que lo revoca.
*/
/*
|------------------------------------------------------------------------------
| Cuentas de redes
|------------------------------------------------------------------------------
| Pegar a mano el id y el token de la cuenta de Instagram de un negocio.
|
| ES UN ATAJO CONSCIENTE. Lo correcto es el Embedded Signup de Meta, donde el
| negocio conecta su propia cuenta y nadie escribe un token -- mismo camino que
| docs/whatsapp-numero-por-negocio.md ya decidio para el otro canal. Vive en
| superadmin y no en el panel del negocio a proposito: pegar un token no es una
| tarea que se le ofrezca a la duena de un spa.
*/
Route::get('/businesses/{business}/social-account', [SocialAccountController::class, 'show'])
    ->name('social-account.show');
Route::post('/businesses/{business}/social-account', [SocialAccountController::class, 'store'])
    ->name('social-account.store');
Route::patch('/businesses/{business}/social-account', [SocialAccountController::class, 'toggle'])
    ->name('social-account.toggle');
Route::delete('/businesses/{business}/social-account', [SocialAccountController::class, 'destroy'])
    ->name('social-account.destroy');

Route::post('/impersonate/{user}', [ImpersonateController::class, 'start'])->name('impersonate.start');
