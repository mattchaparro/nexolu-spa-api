<?php

use App\Http\Controllers\Api\V1\Admin\ResourceAdminController;
use App\Http\Controllers\Api\V1\Admin\ServiceAdminController;
use App\Http\Controllers\Api\V1\AgendaController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Todo va autenticado salvo lo que cuelga de /public, que es la reserva
| online. A diferencia de Blue Souls -- donde /api/external/* permitia crear
| y borrar citas y enumerar clientes con telefono sin ninguna credencial --
| aca no existe una superficie sin autenticar que escriba con privilegios.
| La reserva publica valida por negocio y solo puede crear citas para si
| misma.
*/

Route::prefix('v1')->group(function () {

    // ---- Sesion ----
    Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'sentry.context'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // ---- Catalogo ----
        Route::prefix('services')->group(function () {
            Route::get('/', [ServiceController::class, 'index']);

            Route::middleware('permission:servicios.gestionar')->group(function () {
                Route::post('/', [ServiceAdminController::class, 'store']);
                // POST y no PUT: el formulario manda multipart por la imagen,
                // y PHP no puebla $_FILES en un PUT.
                Route::post('/{service}', [ServiceAdminController::class, 'update']);
                Route::delete('/{service}', [ServiceAdminController::class, 'destroy']);
            });
        });

        Route::prefix('resources')->group(function () {
            Route::get('/', [ResourceController::class, 'index']);
            Route::get('/{resource}/schedules', [ResourceAdminController::class, 'schedules'])
                ->middleware('permission:citas.ver');

            Route::middleware('permission:recursos.gestionar')->group(function () {
                Route::post('/', [ResourceAdminController::class, 'store']);
                Route::post('/{resource}', [ResourceAdminController::class, 'update']);
            });

            Route::put('/{resource}/schedules', [ResourceAdminController::class, 'saveSchedules'])
                ->middleware('permission:horarios.gestionar');
        });

        // ---- Agenda ----
        Route::get('/agenda', [AgendaController::class, 'index'])->middleware('permission:citas.ver');

        Route::prefix('availability')->group(function () {
            Route::get('/', [AvailabilityController::class, 'index'])->middleware('permission:citas.ver');
        });

        Route::prefix('appointments')->middleware('feature:scheduling')->group(function () {
            Route::get('/', [AppointmentController::class, 'index'])->middleware('permission:citas.ver');
            Route::post('/', [AppointmentController::class, 'store'])->middleware('permission:citas.crear');
            Route::patch('/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('permission:citas.editar');
            Route::post('/{appointment}/cancel', [AppointmentController::class, 'cancel'])->middleware('permission:citas.cancelar');
            Route::post('/{appointment}/checkout', [CheckoutController::class, 'store'])->middleware('permission:caja.cobrar');
            Route::delete('/{appointment}/checkout', [CheckoutController::class, 'destroy'])->middleware('permission:caja.cobrar');
        });

        Route::get('/payment-methods', [CheckoutController::class, 'paymentMethods']);

        Route::prefix('clients')->middleware('feature:clients')->group(function () {
            Route::get('/', [ClientController::class, 'index'])->middleware('permission:clientes.ver');
            Route::post('/', [ClientController::class, 'store'])->middleware('permission:clientes.gestionar');
        });
    });

    /*
    |----------------------------------------------------------------------
    | Reserva publica (fase 06)
    |----------------------------------------------------------------------
    | Sin autenticar por diseno, pero alcance minimo: consultar el catalogo
    | y la disponibilidad de UN negocio, y crear una cita para ese mismo
    | negocio. Nunca listar clientes, nunca cancelar, nunca cobrar.
    */
    Route::prefix('public/{business:slug}')
        ->middleware('throttle:30,1')
        ->group(function () {
            Route::get('/services', fn () => abort(501, 'Pendiente: fase 06'));
            Route::get('/availability', fn () => abort(501, 'Pendiente: fase 06'));
            Route::post('/appointments', fn () => abort(501, 'Pendiente: fase 06'));
        });
});

/*
|--------------------------------------------------------------------------
| Contrato con IA Core (fase 05)
|--------------------------------------------------------------------------
| Mismo patron que nexolu-pos-api: el Core nunca ejecuta una herramienta, le
| pega de vuelta a esta API. El despachador revalida business_id y user_id
| contra la base y nunca confia en el context que llega en el cuerpo.
*/
Route::prefix('ai')->middleware('ia-core.key')->group(function () {
    Route::get('/tools/catalog', fn () => abort(501, 'Pendiente: fase 05'));
    Route::post('/tools/invoke', fn () => abort(501, 'Pendiente: fase 05'));
});

/*
|--------------------------------------------------------------------------
| Webhooks de Nexolu Communications (fase 05)
|--------------------------------------------------------------------------
| Firmados con HMAC (X-Nexolu-Signature / X-Nexolu-Timestamp). Nunca apuntar
| aca un webhook de Meta directo.
*/
Route::prefix('webhooks')->group(function () {
    Route::post('/nexolu-comms/whatsapp', fn () => abort(501, 'Pendiente: fase 05'));
});
