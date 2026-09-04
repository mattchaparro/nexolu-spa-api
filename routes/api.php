<?php

use App\Http\Controllers\Api\AiToolInvokeController;
use App\Http\Controllers\Api\CommsWebhookController;
use App\Http\Controllers\Api\V1\Admin\BreakController;
use App\Http\Controllers\Api\V1\Admin\BusinessPaymentMethodController;
use App\Http\Controllers\Api\V1\Admin\CampaignController;
use App\Http\Controllers\Api\V1\Admin\LocationController;
use App\Http\Controllers\Api\V1\Admin\LoyaltyProgramController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PublicPageController;
use App\Http\Controllers\Api\V1\Admin\ResourceAdminController;
use App\Http\Controllers\Api\V1\Admin\ServiceAdminController;
use App\Http\Controllers\Api\V1\Admin\ServiceCategoryController;
use App\Http\Controllers\Api\V1\Admin\ServicePackageController;
use App\Http\Controllers\Api\V1\Admin\SocialPostController;
use App\Http\Controllers\Api\V1\AgendaController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\CashController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientPortalController;
use App\Http\Controllers\Api\V1\ClientProfileController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\LoyaltyCardController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\MyWorkController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\SalesReportController;
use App\Http\Controllers\Api\V1\ServiceClosingController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\StageController;
use App\Http\Controllers\Api\V1\SurveyController;
use App\Http\Controllers\Api\V1\WaitlistAdminController;
use App\Http\Controllers\Api\V1\WaitlistController;
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

            /*
             * Una cita suelta, por id. La necesita quien llega a una cita sin
             * pasar por la rejilla del dia -- «Mi dia» cobrando lo que se
             * quedo sin registrar ayer.
             */
            Route::get('/{appointment}', [AppointmentController::class, 'show'])
                ->middleware('permission:citas.ver');
            Route::patch('/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('permission:citas.editar');
            Route::post('/{appointment}/cancel', [AppointmentController::class, 'cancel'])->middleware('permission:citas.cancelar');
            Route::post('/{appointment}/checkout', [CheckoutController::class, 'store'])->middleware('permission:caja.cobrar');
            Route::delete('/{appointment}/checkout', [CheckoutController::class, 'destroy'])->middleware('permission:caja.cobrar');

            // El abono con que el cliente separo. Mismo permiso que cobrar:
            // es plata que entra y tiene que quedar en una cuenta.
            Route::post('/{appointment}/deposit', [DepositController::class, 'store'])->middleware('permission:caja.cobrar');
            Route::delete('/{appointment}/deposit', [DepositController::class, 'destroy'])->middleware('permission:caja.cobrar');

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

            /*
             * Lo que se sube al cerrar el servicio.
             *
             * NO piden `clientes.gestionar`, y esa es toda la razon por la que
             * viven aca y no colgadas de la ficha: el rol de profesional no
             * tiene acceso a la base de clientes -- a proposito, es el activo
             * del negocio -- pero fotografiar el trabajo que uno acaba de
             * hacer no es administrar la ficha de nadie. Quien puede subir se
             * limita por la AGENDA (es tu cita), no por la ficha.
             */
            Route::post('/{appointment}/work-photo', [ServiceClosingController::class, 'storePhoto'])
                ->middleware(['feature:client_history', 'permission:servicios.registrar,caja.cobrar']);

            // El comprobante es plata: mismo permiso que cobrar.
            Route::post('/{appointment}/payment-proof', [ServiceClosingController::class, 'storePaymentProof'])
                ->middleware('permission:caja.cobrar');
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

        /*
         * Que SEDES ve alguien. Eje distinto de QUE puede hacer, y por eso
         * ruta aparte: al administrador de un local no se le editan permisos
         * -- los tiene todos por su rol -- pero si se le acota el local.
         *
         * Sin `feature:multi_location`: con una sola sede la pantalla no lo
         * muestra, y si alguien llama igual, sincronizar la unica sede que
         * existe no cambia nada.
         */
        Route::put('/permissions/{user}/locations', [PermissionController::class, 'updateLocations'])
            ->middleware('permission:permisos.gestionar');

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
            // Sobre que valor se paga comision cuando hubo descuento.
            Route::put('/commission-bases', [PayrollController::class, 'updateCommissionBases']);
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

            /*
             * El permiso de la clienta para que su foto salga en las redes.
             *
             * Vive en la ficha y NO en el modulo de publicaciones, aunque sea
             * ese el que lo usa. El permiso se pide donde esta la clienta --
             * en el mostrador, mirandose las manos -- y no dos semanas
             * despues, cuando alguien arma el calendario y ya no tiene a
             * quien preguntarle. Sin bandera de `social_posts`: anotar que
             * alguien dijo que si es cierto tenga o no el negocio el modulo.
             */
            Route::post('/photos/{photo}/marketing-consent', [ClientProfileController::class, 'updatePhotoConsent'])
                ->middleware('permission:clientes.gestionar');

            // La tarjeta de sellos de esa persona. Mismo permiso que el
            // historial: cuantas veces vino es exactamente eso.
            Route::get('/{client}/loyalty', [LoyaltyCardController::class, 'show'])
                ->middleware(['feature:loyalty', 'permission:clientes.historial']);
        });

        /*
        |----------------------------------------------------------------------
        | Fidelizacion
        |----------------------------------------------------------------------
        | La tarjeta de sellos. Detras de su bandera de plan: es una funcion
        | contratable, no parte del nucleo.
        */
        /*
        |----------------------------------------------------------------------
        | Campanas de temporada
        |----------------------------------------------------------------------
        */
        Route::prefix('campaigns')
            ->middleware(['feature:promotions', 'permission:servicios.gestionar'])
            ->group(function () {
                Route::get('/', [CampaignController::class, 'index']);
                Route::post('/', [CampaignController::class, 'store']);
                Route::post('/{campaign}', [CampaignController::class, 'store']);
                Route::delete('/{campaign}', [CampaignController::class, 'destroy']);
            });

        /*
        |----------------------------------------------------------------------
        | Sedes
        |----------------------------------------------------------------------
        | Sin `feature:multi_location` en el listado a proposito: la pantalla
        | de sedes tiene que poder mostrar la unica sede que ya existe, y el
        | selector de sede al crear a alguien la necesita. Lo que la bandera
        | controla es ABRIR la segunda, y eso se defiende en `store()` con el
        | tope del plan.
        */
        /*
        |----------------------------------------------------------------------
        | Mensajes: la bandeja de salida
        |----------------------------------------------------------------------
        | Existe sobre todo por el MODO MANUAL -- como opera un negocio mientras
        | no tenga un numero de WhatsApp aprobado, y como van a querer seguir
        | operando algunos. Sin esta pantalla, un aviso que el sistema preparo
        | no lo ve nadie.
        |
        | Con `citas.ver` y no con un permiso propio: quien atiende el mostrador
        | es quien manda estos mensajes, y ya tiene ese permiso.
        */
        Route::prefix('messages')->middleware('permission:citas.ver')->group(function () {
            Route::get('/', [MessageController::class, 'index']);
            Route::post('/{message}/sent', [MessageController::class, 'markSent']);
            Route::post('/{message}/retry', [MessageController::class, 'retry']);
            Route::delete('/{message}', [MessageController::class, 'destroy']);
        });

        // Quien espera cupo, visto desde el mostrador. Mismo criterio que los
        // mensajes: lo maneja quien atiende, y ya tiene citas.ver.
        Route::prefix('waitlist')->middleware('permission:citas.ver')->group(function () {
            Route::get('/', [WaitlistAdminController::class, 'index']);
            Route::post('/{entry}/stop', [WaitlistAdminController::class, 'stop']);
        });

        Route::prefix('locations')
            ->middleware('permission:negocio.configurar')
            ->group(function () {
                Route::get('/', [LocationController::class, 'index']);
                Route::post('/', [LocationController::class, 'store'])
                    ->middleware('feature:multi_location');
                Route::post('/{location}', [LocationController::class, 'update']);
                Route::post('/{location}/primary', [LocationController::class, 'makePrimary']);
                Route::delete('/{location}', [LocationController::class, 'disable']);
            });

        /*
        |----------------------------------------------------------------------
        | Publicaciones en redes
        |----------------------------------------------------------------------
        | Un permiso solo para todo el modulo: quien puede preparar el
        | contenido puede programarlo y marcarlo publicado. Partirlo en ver y
        | gestionar seria inventar una separacion que ningun spa pidio -- lo
        | maneja una persona, casi siempre la duena.
        |
        | NO HAY RUTA QUE PUBLIQUE. No es un olvido: ver PostDispatcher.
        */
        Route::prefix('social-posts')
            ->middleware(['feature:social_posts', 'permission:publicaciones.gestionar'])
            ->group(function () {
                Route::get('/', [SocialPostController::class, 'index']);

                // Las fotos de la ficha que SI se pueden publicar. Filtra por
                // consentimiento y no acepta un parametro para saltarselo.
                Route::get('/photo-pool', [SocialPostController::class, 'photoPool']);

                // "Busca ideas ahora", para quien abre la pantalla y la
                // encuentra vacia. Idempotente.
                Route::post('/plan', [SocialPostController::class, 'plan']);

                Route::post('/', [SocialPostController::class, 'store']);

                /*
                 * "Crear publicacion" desde las fotos de un servicio. Cierra
                 * el circulo: la foto se tomo al cerrar el servicio, la
                 * clienta dio permiso, y de ahi sale el borrador.
                 */
                Route::post('/from-photos', [SocialPostController::class, 'fromPhotos']);

                // POST y no PUT tambien al editar: el formulario manda
                // multipart por la imagen y PHP no puebla $_FILES en un PUT.
                Route::post('/{post}', [SocialPostController::class, 'update']);

                Route::post('/{post}/compose', [SocialPostController::class, 'compose']);
                Route::post('/{post}/schedule', [SocialPostController::class, 'schedule']);
                /*
                 * Publicar de verdad, contra Instagram. Solo sirve si el
                 * negocio conecto su cuenta; sin ella, el camino es
                 * `published` -- copiar, pegar y marcar.
                 */
                Route::post('/{post}/publish', [SocialPostController::class, 'publishNow']);

                Route::post('/{post}/published', [SocialPostController::class, 'markPublished']);
                Route::delete('/{post}', [SocialPostController::class, 'discard']);
            });

        Route::prefix('loyalty')->middleware('feature:loyalty')->group(function () {
            Route::get('/program', [LoyaltyProgramController::class, 'show'])
                ->middleware('permission:servicios.gestionar');
            Route::post('/program', [LoyaltyProgramController::class, 'store'])
                ->middleware('permission:servicios.gestionar');
            Route::delete('/program', [LoyaltyProgramController::class, 'destroy'])
                ->middleware('permission:servicios.gestionar');
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
    /*
    |----------------------------------------------------------------------
    | Encuesta de satisfaccion
    |----------------------------------------------------------------------
    | Sin autenticar y por token del enlace: quien responde es una clienta con
    | un mensaje de WhatsApp, no alguien con cuenta. Va fuera del prefijo del
    | negocio porque el token ya identifica la cita, y pedir ademas el slug
    | solo agrega una forma mas de que el enlace quede mal armado.
    */
    Route::prefix('survey/{token}')
        ->middleware('throttle:pagina-publica')
        ->group(function () {
            Route::get('/', [SurveyController::class, 'show']);
            Route::post('/', [SurveyController::class, 'store']);
        });

    /*
    |--------------------------------------------------------------------------
    | "Mis citas": el cliente, sin cuenta
    |--------------------------------------------------------------------------
    | Se entra por un TOKEN, nunca por telefono. Un telefono no es un secreto
    | -- esta en la vitrina, en Instagram, en un grupo de WhatsApp -- y dejar
    | consultar por el convierte esto en un directorio: se prueban numeros y
    | salen nombres, servicios y horarios de clientas ajenas. Es exactamente lo
    | que hacia `/api/external/*` en Blue Souls.
    |
    | El limite de escritura es el mismo de la reserva publica: mirar es
    | barato, mover la agenda del negocio no.
    */
    Route::prefix('public/{business:slug}/mis-citas/{token}')
        ->middleware('throttle:pagina-publica')
        ->group(function () {
            Route::get('/', [ClientPortalController::class, 'show']);
            Route::get('/{appointment}/slots', [ClientPortalController::class, 'slots']);

            Route::middleware('throttle:reserva-publica')->group(function () {
                Route::post('/{appointment}/reschedule', [ClientPortalController::class, 'reschedule']);
                Route::post('/{appointment}/cancel', [ClientPortalController::class, 'cancel']);
            });
        });

    Route::prefix('public/{business:slug}')
        ->middleware('throttle:pagina-publica')
        ->group(function () {
            Route::get('/', [PublicBookingController::class, 'show']);
            Route::get('/services', [PublicBookingController::class, 'services']);
            Route::get('/days', [PublicBookingController::class, 'days']);
            Route::get('/availability', [PublicBookingController::class, 'availability']);
            // Varios servicios seguidos, o un combo.
            Route::get('/availability/chain', [PublicBookingController::class, 'chain']);

            // Lo unico que escribe. Con su propio limite, mas apretado que el
            // de lectura: mirar la pagina es gratis, llenar la agenda no.
            Route::post('/appointments', [PublicBookingController::class, 'store'])
                ->middleware('throttle:reserva-publica');

            /*
             * Lista de espera: "avisame si se libera algo". El cupo liberado
             * se anuncia a todos los que encajan y es de quien lo tome primero
             * — el arbitro es el indice unico de resource_occupancy.
             */
            Route::post('/waitlist', [WaitlistController::class, 'store'])
                ->middleware('throttle:reserva-publica');

            Route::prefix('cupo/{token}')->group(function () {
                Route::get('/', [WaitlistController::class, 'show']);

                Route::middleware('throttle:reserva-publica')->group(function () {
                    Route::post('/take', [WaitlistController::class, 'take']);
                    Route::post('/stop', [WaitlistController::class, 'stop']);
                });
            });
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
    Route::get('/tools/catalog', [AiToolInvokeController::class, 'catalog']);
    Route::post('/tools/invoke', [AiToolInvokeController::class, 'invoke']);
});

/*
|--------------------------------------------------------------------------
| Webhooks de Nexolu Communications (fase 05)
|--------------------------------------------------------------------------
| Firmados con HMAC (X-Nexolu-Signature / X-Nexolu-Timestamp). Nunca apuntar
| aca un webhook de Meta directo.
*/
Route::prefix('webhooks')->group(function () {
    Route::post('/nexolu-comms/whatsapp', [CommsWebhookController::class, 'whatsapp']);
});
