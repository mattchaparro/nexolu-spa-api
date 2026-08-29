<?php

use App\Http\Controllers\Api\V1\Admin\BreakController;
use App\Http\Controllers\Api\V1\Admin\BusinessPaymentMethodController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PublicPageController;
use App\Http\Controllers\Api\V1\Admin\ResourceAdminController;
use App\Http\Controllers\Api\V1\Admin\ServiceAdminController;
use App\Http\Controllers\Api\V1\Admin\ServiceCategoryController;
use App\Http\Controllers\Api\V1\Admin\ServicePackageController;
use App\Http\Controllers\Api\V1\AgendaController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\CashController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientProfileController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\MyWorkController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\SalesReportController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\StageController;
use App\Http\Controllers\Api\V1\WalkInController;
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
        /*
         * Categorias de servicios. Existen sobre todo por la comision: son el
         * ultimo escalon de la cascada, y permiten mover una familia entera
         * -- los 20 de manicure -- sin entrar a cada ficha.
         */
        Route::prefix('service-categories')->group(function () {
            Route::get('/', [ServiceCategoryController::class, 'index'])
                ->middleware('permission:servicios.gestionar');

            Route::middleware('permission:servicios.gestionar')->group(function () {
                Route::post('/', [ServiceCategoryController::class, 'store']);
                Route::put('/{category}', [ServiceCategoryController::class, 'update']);
                Route::delete('/{category}', [ServiceCategoryController::class, 'destroy']);
            });
        });

        Route::put('/services/bulk-commission', [ServiceCategoryController::class, 'bulkCommission'])
            ->middleware('permission:servicios.gestionar');

        /*
         * Combos. Tabla propia y no un servicio con bandera: un combo no tiene
         * duracion ni precio propios -- salen de sus partes -- y meterlo entre
         * los servicios obligaria a que cada consulta del catalogo se acuerde
         * de filtrarlo.
         */
        Route::prefix('service-packages')->group(function () {
            // Lo lee quien agenda, no solo quien administra el catalogo.
            Route::get('/', [ServicePackageController::class, 'index'])
                ->middleware('permission:citas.ver');

            Route::middleware('permission:servicios.gestionar')->group(function () {
                // POST y no PUT tambien al editar: el formulario manda
                // multipart por la imagen y PHP no puebla $_FILES en un PUT.
                Route::post('/', [ServicePackageController::class, 'store']);
                Route::post('/{package}', [ServicePackageController::class, 'update']);
                Route::delete('/{package}', [ServicePackageController::class, 'destroy']);
            });
        });

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

        // Almuerzos y descansos. Mismo permiso que los horarios: es la misma
        // decision -- cuando se atiende y cuando no.
        Route::prefix('breaks')->group(function () {
            Route::get('/', [BreakController::class, 'index'])->middleware('permission:citas.ver');

            Route::middleware('permission:horarios.gestionar')->group(function () {
                Route::post('/', [BreakController::class, 'store']);
                Route::put('/{break}', [BreakController::class, 'update']);
                Route::delete('/{break}', [BreakController::class, 'destroy']);
            });
        });

        // ---- Agenda ----
        Route::get('/agenda', [AgendaController::class, 'index'])->middleware('permission:citas.ver');

        Route::prefix('availability')->group(function () {
            Route::get('/', [AvailabilityController::class, 'index'])->middleware('permission:citas.ver');
            
            // Donde cabe una visita de varios servicios, uno detras de otro.
            Route::get('/chain', [AvailabilityController::class, 'chain'])
                ->middleware('permission:citas.ver');
        });

        Route::prefix('appointments')->middleware('feature:scheduling')->group(function () {
            Route::get('/', [AppointmentController::class, 'index'])->middleware('permission:citas.ver');
            Route::post('/', [AppointmentController::class, 'store'])->middleware('permission:citas.crear');
            Route::patch('/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('permission:citas.editar');
            Route::post('/{appointment}/cancel', [AppointmentController::class, 'cancel'])->middleware('permission:citas.cancelar');
            Route::post('/{appointment}/checkout', [CheckoutController::class, 'store'])->middleware('permission:caja.cobrar');
            Route::delete('/{appointment}/checkout', [CheckoutController::class, 'destroy'])->middleware('permission:caja.cobrar');

            /*
             * Mover de etapa. Detras de `citas.editar` y no de un permiso
             * propio: mover una cita a "confirmada" o "no asistio" es
             * gestionar la agenda, y quien puede reagendar ya puede hacer
             * cosas mas grandes que eso.
             */
            Route::get('/{appointment}/stages', [StageController::class, 'options'])
                ->middleware('permission:citas.ver');
            Route::post('/{appointment}/stage', [StageController::class, 'move'])
                ->middleware('permission:citas.editar');
            Route::get('/{appointment}/history', [StageController::class, 'history'])
                ->middleware('permission:citas.ver');
        });

        // El flujo del negocio, para pintar el selector.
        Route::get('/workflow', [StageController::class, 'workflow'])->middleware('permission:citas.ver');

        Route::get('/payment-methods', [CheckoutController::class, 'paymentMethods']);

        // Que medios acepta ESTE negocio, elegidos del catalogo global.
        Route::get('/payment-methods/catalog', [BusinessPaymentMethodController::class, 'index'])
            ->middleware('permission:negocio.configurar');
        Route::put('/payment-methods/catalog', [BusinessPaymentMethodController::class, 'sync'])
            ->middleware('permission:negocio.configurar');

        // La pagina publica del negocio, desde adentro.
        Route::prefix('public-page')
            ->middleware(['feature:online_booking', 'permission:negocio.configurar'])
            ->group(function () {
                Route::get('/', [PublicPageController::class, 'show']);
                // POST y no PUT: el formulario manda multipart por la portada,
                // y PHP no puebla $_FILES en un PUT.
                Route::post('/', [PublicPageController::class, 'update']);
                Route::put('/services', [PublicPageController::class, 'syncServices']);
            });

        // Quien puede hacer que, persona por persona.
        Route::get('/permissions', [PermissionController::class, 'index'])
            ->middleware(['feature:permissions_management', 'permission:permisos.gestionar']);
        Route::put('/permissions/{user}', [PermissionController::class, 'update'])
            ->middleware(['feature:permissions_management', 'permission:permisos.gestionar']);

        // Lo que ve una profesional de si misma: su agenda del dia, lo que
        // lleva ganado y lo que le falta cobrar.
        Route::get('/my-work', [MyWorkController::class, 'summary']);

        // Alguien que llega sin cita: registrar y cobrar en un paso.
        // Permiso propio, no el de agendar: registrar lo que YA se hizo no es
        // tocar la agenda. Quien puede agendar tambien puede registrar.
        Route::post('/walk-in', [WalkInController::class, 'store'])
            ->middleware('permission:servicios.registrar,citas.crear');

        /*
        |----------------------------------------------------------------------
        | Caja y dinero
        |----------------------------------------------------------------------
        | El CIERRE DEL DIA es lo central: comprobar que lo que hay coincide
        | con lo que cada profesional registro. Va detras de `cash_closing`.
        |
        | El TURNO es opcional y viene apagado por defecto (`cash_shift`). En
        | un spa nadie abre y cierra caja por turnos; existe para el negocio
        | que si tenga una cajera dedicada.
        */
        Route::prefix('cash')->group(function () {
            Route::middleware(['feature:cash_shift', 'permission:caja.turno'])->group(function () {
                Route::get('/shift', [CashController::class, 'currentShift']);
                Route::post('/shift/open', [CashController::class, 'openShift']);
                Route::post('/shift/close', [CashController::class, 'closeShift']);
            });

            Route::middleware(['feature:cash_closing', 'permission:caja.cierre'])->group(function () {
                Route::get('/closing/preview', [CashController::class, 'closingPreview']);
                Route::post('/closing', [CashController::class, 'closeDay']);
                Route::get('/closings', [CashController::class, 'closings']);
                Route::delete('/closings/{closing}', [CashController::class, 'undoClosing']);
            });
        });

        // El resumen del dia: lo que el dueno mira al cerrar la jornada.
        Route::get('/daily-summary', [CashController::class, 'dailySummary'])
            ->middleware('permission:reportes.ver');

        /*
         * Reporte de ventas con filtros. Distinto del cierre, que responde
         * "cuanto efectivo deberia haber ahora": esto responde "como nos fue"
         * sobre un rango, y por persona o por medio de pago.
         */
        Route::get('/reports/sales', [SalesReportController::class, 'index'])
            ->middleware(['feature:reports', 'permission:reportes.ver']);

        Route::prefix('expenses')->middleware('feature:expenses')->group(function () {
            Route::get('/types', [ExpenseController::class, 'types']);
            Route::post('/types', [ExpenseController::class, 'storeType'])->middleware('permission:gastos.gestionar');

            Route::get('/', [ExpenseController::class, 'index'])->middleware('permission:gastos.gestionar');
            Route::post('/', [ExpenseController::class, 'store'])->middleware('permission:gastos.gestionar');
            Route::post('/{expense}', [ExpenseController::class, 'update'])->middleware('permission:gastos.gestionar');
            Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:gastos.gestionar');
        });

        /*
        |----------------------------------------------------------------------
        | Nomina
        |----------------------------------------------------------------------
        | Lo que el negocio le paga a cada profesional. No es nomina legal
        | (prestaciones, PILA): eso lo lleva el contador. Aca vive el control
        | operativo que hoy se hace en una libreta.
        */
        Route::prefix('payroll')->middleware(['feature:payroll', 'permission:nomina.gestionar'])->group(function () {
            Route::get('/pending', [PayrollController::class, 'pending']);
            Route::get('/settlements', [PayrollController::class, 'index']);
            Route::get('/settlements/{settlement}', [PayrollController::class, 'showSettlement']);
            Route::delete('/settlements/{settlement}', [PayrollController::class, 'destroySettlement']);

            Route::get('/resources/{resource}/preview', [PayrollController::class, 'preview']);
            Route::post('/resources/{resource}/settle', [PayrollController::class, 'settle']);

            Route::get('/adjustments', [PayrollController::class, 'adjustments']);
            Route::post('/adjustments', [PayrollController::class, 'storeAdjustment']);
            Route::delete('/adjustments/{adjustment}', [PayrollController::class, 'destroyAdjustment']);

            // Como se le paga a cada una: modo, base y hasta cuando.
            Route::get('/compensation', [PayrollController::class, 'compensation']);
            Route::put('/compensation/{resource}', [PayrollController::class, 'updateCompensation']);
        });

        Route::prefix('clients')->middleware('feature:clients')->group(function () {
            // Buscador del mostrador: minimo, para elegir en un desplegable.
            Route::get('/search', [ClientController::class, 'index'])->middleware('permission:clientes.ver');
            Route::post('/', [ClientController::class, 'store'])->middleware('permission:clientes.gestionar');

            // La ficha completa. Va detras de su propio permiso: ver el
            // historial de alguien es mas que poder elegirlo en un buscador.
            Route::get('/', [ClientProfileController::class, 'index'])->middleware('permission:clientes.ver');
            Route::get('/{client}', [ClientProfileController::class, 'show'])
                ->middleware('permission:clientes.historial');
            Route::patch('/{client}', [ClientProfileController::class, 'update'])
                ->middleware('permission:clientes.gestionar');
            Route::post('/{client}/photos', [ClientProfileController::class, 'storePhoto'])
                ->middleware('permission:clientes.gestionar');
            Route::delete('/photos/{photo}', [ClientProfileController::class, 'destroyPhoto'])
                ->middleware('permission:clientes.gestionar');
        });

        /*
        |----------------------------------------------------------------------
        | Plataforma
        |----------------------------------------------------------------------
        | Cruzan todos los negocios a proposito. Viven detras de su propio
        | middleware y en su propio archivo para que esa excepcion al
        | aislamiento sea visible, no algo que haya que descubrir leyendo.
        */
        Route::prefix('superadmin')
            ->middleware('superadmin')
            ->name('superadmin.')
            ->group(base_path('routes/superadmin.php'));
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
        ->middleware('throttle:pagina-publica')
        ->group(function () {
            Route::get('/', [PublicBookingController::class, 'show']);
            Route::get('/services', [PublicBookingController::class, 'services']);
            Route::get('/days', [PublicBookingController::class, 'days']);
            Route::get('/availability', [PublicBookingController::class, 'availability']);

            // Lo unico que escribe. Con su propio limite, mas apretado que el
            // de lectura: mirar la pagina es gratis, llenar la agenda no.
            Route::post('/appointments', [PublicBookingController::class, 'store'])
                ->middleware('throttle:reserva-publica');
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
