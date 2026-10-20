<?php


use App\Http\Controllers\GuestLocaleController;
use App\Http\Controllers\UserLocaleController;
use App\Http\Controllers\StoreDocumentSettingController;
use App\Http\Controllers\PosClosingReportController;
use App\Http\Controllers\PosCashMovementController;
use App\Http\Controllers\PosSessionController;
use App\Http\Controllers\PosRegisterController;
use App\Http\Controllers\Admin\SendOwnerInvitationController;
use App\Http\Controllers\OwnerInvitationController;
use App\Http\Controllers\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\EmployeeInvitationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReturnRequestController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\PublicCheckoutController;
use App\Http\Controllers\PublicCartController;
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\StoreSettingController;
use App\Http\Controllers\MobileMoneyReconciliationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;

// Choix de langue des visiteurs non connectés.
Route::post(
    '/locale',
    [GuestLocaleController::class, 'update']
)->name('guest.locale.update');

// Langue personnelle de l'utilisateur.
// Route globale, indépendante des boutiques.
Route::middleware('auth')->patch(
    '/account/locale',
    [UserLocaleController::class, 'update']
)->name('account.locale.update');

Route::get('/', function () {
    return view('welcome');
});

// Authentification des utilisateurs des boutiques.

Route::get(
    '/login',
    [SessionController::class, 'create']
)->name('login');

Route::post(
    '/login',
    [SessionController::class, 'store']
)
    ->middleware('throttle:5,1')
    ->name('login.store');

Route::post(
    '/logout',
    [SessionController::class, 'destroy']
)
    ->middleware('auth')
    ->name('logout');

// Administration générale de ZEMBOA.
// Indépendante des boutiques commerçantes.

Route::prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::get(
            '/login',
            [AdminSessionController::class, 'create']
        )->name('login');

        Route::post(
            '/login',
            [AdminSessionController::class, 'store']
        )
            ->middleware('throttle:5,1')
            ->name('login.store');

        Route::post(
            '/logout',
            [AdminSessionController::class, 'destroy']
        )
            ->middleware('auth')
            ->name('logout');

        Route::middleware(['auth', 'admin'])
            ->group(function () {

                Route::get(
                    '/',
                    [AdminSessionController::class, 'dashboard']
                )->name('dashboard');

                // Gestion des boutiques par le super administrateur.

                Route::get(
                    '/stores',
                    [AdminStoreController::class, 'index']
                )->name('stores.index');

                Route::post(
                    '/stores',
                    [AdminStoreController::class, 'store']
                )->name('stores.store');

                Route::post(
                    '/stores/{storeId}/owner-invitation',
                    SendOwnerInvitationController::class
                )
                    ->whereNumber('storeId')
                    ->middleware('throttle:3,1')
                    ->name('stores.owner-invitation.send');

                // Consulter les détails d'une boutique.

                Route::get(
                    '/stores/{storeId}',
                    [AdminStoreController::class, 'show']
                )
                    ->whereNumber('storeId')
                    ->name('stores.show');

                // Activer ou suspendre une boutique.

                Route::patch(
                    '/stores/{storeId}/status',
                    [AdminStoreController::class, 'updateStatus']
                )
                    ->whereNumber('storeId')
                    ->name('stores.update-status');

                // Historique des décisions administratives.

                Route::get(
                    '/stores/{storeId}/status-events',
                    [AdminStoreController::class, 'statusEvents']
                )
                    ->whereNumber('storeId')
                    ->name('stores.status-events.index');
            });
    });

Route::middleware(['auth', 'tenant'])->group(function () {

    Route::get(
        '/account',
        [SessionController::class, 'me']
    )->name('account.show');

    Route::get('/categories', [CategoryController::class, 'index'])
        ->name('categories.index');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->name('categories.store');

    Route::put('/categories/{category}', [CategoryController::class, 'update'])
        ->name('categories.update');

    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->name('categories.destroy');

    Route::post('/products', [ProductController::class, 'store'])
        ->name('products.store');

    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->name('products.show');

    Route::put('/products/{product}', [ProductController::class, 'update'])
        ->name('products.update');

    Route::delete('/products/{product}', [ProductController::class, 'destroy'])
        ->name('products.destroy');

    Route::post(
        '/products/{product}/variants',
        [ProductVariantController::class, 'store']
    )->name('product-variants.store');

    Route::get(
        '/product-variants/{productVariant}',
        [ProductVariantController::class, 'show']
    )->name('product-variants.show');

    Route::put(
        '/product-variants/{productVariant}',
        [ProductVariantController::class, 'update']
    )->name('product-variants.update');

    Route::delete(
        '/product-variants/{productVariant}',
        [ProductVariantController::class, 'destroy']
    )->name('product-variants.destroy');

    Route::get('/stock', [StockController::class, 'index'])
        ->name('stock.index');

    Route::get(
        '/stock/movements',
        [StockController::class, 'movements']
    )->name('stock.movements');


    Route::get('/stock/{stockLevel}', [StockController::class, 'show'])
        ->name('stock.show');

    Route::post('/stock/{stockLevel}/receive', [StockController::class, 'receive'])
        ->name('stock.receive');

    Route::post('/stock/{stockLevel}/adjust-in', [StockController::class, 'adjustIn'])
        ->name('stock.adjust-in');

    Route::post('/stock/{stockLevel}/adjust-out', [StockController::class, 'adjustOut'])
        ->name('stock.adjust-out');

    Route::get('/customers', [CustomerController::class, 'index'])
        ->name('customers.index');

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show');

    Route::post('/customers', [CustomerController::class, 'store'])
        ->name('customers.store');

    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
        ->name('customers.update');

    // Réconciliation Mobile Money
    // Consultation des dossiers de la boutique.
    Route::get(
        '/payments/reconciliations',
        [MobileMoneyReconciliationController::class, 'index']
    )->name('payments.reconciliations.index');


    // Consulter les détails d'un dossier
    // de réconciliation de sa boutique.
    Route::get(
        '/payments/reconciliations/{caseId}',
        [MobileMoneyReconciliationController::class, 'show']
    )->whereNumber('caseId')
        ->name('payments.reconciliations.show');

    // Clôturer administrativement un dossier
    // de réconciliation Mobile Money.
    Route::patch(
        '/payments/reconciliations/{caseId}/resolve',
        [MobileMoneyReconciliationController::class, 'resolve']
    )->whereNumber('caseId')
        ->name('payments.reconciliations.resolve');


    // Paramètres de la boutique courante

    Route::get(
        '/store/settings',
        [StoreSettingController::class, 'show']
    )->name('store.settings.show');

    Route::patch(
        '/store/settings',
        [StoreSettingController::class, 'update']
    )->name('store.settings.update');

    // Consultation des commandes ZEMBOA.
    // Propriétaire et gestionnaire autorisés.

    Route::get(
        '/orders',
        [OrderController::class, 'index']
    )->name('orders.index');

    Route::get(
        '/orders/{orderId}',
        [OrderController::class, 'show']
    )
        ->whereNumber('orderId')
        ->name('orders.show');


    /*
    |--------------------------------------------------------------------------
    | Gestion des livraisons — ZEMBOA
    |--------------------------------------------------------------------------
    */

    // Consulter les livraisons accessibles à l'utilisateur.
    Route::get(
        '/deliveries',
        [DeliveryController::class, 'index']
    )->name('deliveries.index');

    // Consulter une livraison précise.
    Route::get(
        '/deliveries/{deliveryId}',
        [DeliveryController::class, 'show']
    )
        ->whereNumber('deliveryId')
        ->name('deliveries.show');

    // Affecter un livreur à une commande.
    Route::post(
        '/orders/{orderId}/delivery/assign',
        [DeliveryController::class, 'assign']
    )
        ->whereNumber('orderId')
        ->name('deliveries.assign');

    // Actualiser le statut de livraison.
    Route::patch(
        '/deliveries/{deliveryId}/status',
        [DeliveryController::class, 'updateStatus']
    )
        ->whereNumber('deliveryId')
        ->name('deliveries.update-status');

    // Confirmer le retour physique d'un colis.
    Route::patch(
        '/deliveries/{deliveryId}/confirm-return',
        [DeliveryController::class, 'confirmReturn']
    )
        ->whereNumber('deliveryId')
        ->name('deliveries.confirm-return');

    /*
    |--------------------------------------------------------------------------
    | Retours SAV — ZEMBOA
    |--------------------------------------------------------------------------
    */

    // Liste des demandes de retour.
    Route::get(
        '/returns',
        [ReturnRequestController::class, 'index']
    )->name('returns.index');

    // Consulter une demande de retour.
    Route::get(
        '/returns/{returnRequestId}',
        [ReturnRequestController::class, 'show']
    )
        ->whereNumber('returnRequestId')
        ->name('returns.show');

    // Créer une demande de retour.
    Route::post(
        '/returns',
        [ReturnRequestController::class, 'store']
    )->name('returns.store');

    // Approuver ou rejeter une demande de retour.
    Route::patch(
        '/returns/{returnRequestId}/review',
        [ReturnRequestController::class, 'review']
    )
        ->whereNumber('returnRequestId')
        ->name('returns.review');

    // Confirmer la réception physique d'un retour.
    Route::patch(
        '/returns/{returnRequestId}/receive',
        [ReturnRequestController::class, 'receive']
    )
        ->whereNumber('returnRequestId')
        ->name('returns.receive');

    // Inspecter les articles retournés.
    Route::patch(
        '/returns/{returnRequestId}/inspect',
        [ReturnRequestController::class, 'inspect']
    )
        ->whereNumber('returnRequestId')
        ->name('returns.inspect');


    // Remettre en stock les articles revendables.
    Route::patch(
        '/returns/{returnRequestId}/restock',
        [ReturnRequestController::class, 'restock']
    )
        ->whereNumber('returnRequestId')
        ->name('returns.restock');

    // Préparer un remboursement simulé
    // après inspection du retour.
    Route::post(
        '/returns/{returnRequestId}/refund',
        [RefundController::class, 'store']
    )
        ->whereNumber('returnRequestId')
        ->name('refunds.prepare');

    // Approuver un remboursement simulé.
    Route::patch(
        '/refunds/{refundId}/approve',
        [RefundController::class, 'approve']
    )
        ->whereNumber('refundId')
        ->name('refunds.approve');

    // Liste des remboursements de la boutique.
    Route::get(
        '/refunds',
        [RefundController::class, 'index']
    )->name('refunds.index');

    // Détails d'un remboursement.
    Route::get(
        '/refunds/{refundId}',
        [RefundController::class, 'show']
    )
        ->whereNumber('refundId')
        ->name('refunds.show');

    // Simulation finale, sans transfert financier.
    Route::patch(
        '/refunds/{refundId}/simulate',
        [RefundController::class, 'simulate']
    )
        ->whereNumber('refundId')
        ->name('refunds.simulate');

    // Gestion des employés — propriétaire uniquement.

    Route::get(
        '/employees',
        [EmployeeController::class, 'index']
    )->name('employees.index');

    Route::post(
        '/employees',
        [EmployeeController::class, 'store']
    )->name('employees.store');

    Route::patch(
        '/employees/{employeeId}/role',
        [EmployeeController::class, 'updateRole']
    )
        ->whereNumber('employeeId')
        ->name('employees.update-role');

    Route::patch(
        '/employees/{employeeId}/status',
        [EmployeeController::class, 'updateStatus']
    )
        ->whereNumber('employeeId')
        ->name('employees.update-status');

    Route::get(
        '/pos/registers',
        [PosRegisterController::class, 'index']
    )->name('pos.registers.index');

    Route::post(
        '/pos/registers',
        [PosRegisterController::class, 'store']
    )->name('pos.registers.store');

    Route::patch(
        '/pos/registers/{registerId}',
        [PosRegisterController::class, 'update']
    )
        ->whereNumber('registerId')
        ->name('pos.registers.update');

    Route::patch(
        '/pos/registers/{registerId}/status',
        [PosRegisterController::class, 'updateStatus']
    )
        ->whereNumber('registerId')
        ->name('pos.registers.update-status');

    // Ouvrir une session de caisse.
    Route::post(
        '/pos/registers/{registerId}/sessions',
        [PosSessionController::class, 'open']
    )
        ->whereNumber('registerId')
        ->name('pos.sessions.open');

    // Clôturer une session de caisse.
    Route::post(
        '/pos/sessions/{sessionId}/close',
        [PosSessionController::class, 'close']
    )
        ->whereNumber('sessionId')
        ->name('pos.sessions.close');

    // Consulter le bilan d'une session POS.
    Route::get(
        '/pos/sessions/{sessionId}/balance',
        [PosSessionController::class, 'balance']
    )
        ->whereNumber('sessionId')
        ->name('pos.sessions.balance');

    // Enregistrer une entrée ou sortie d'espèces.
    Route::post(
        '/pos/sessions/{sessionId}/cash-movements',
        [PosCashMovementController::class, 'store']
    )
        ->whereNumber('sessionId')
        ->name('pos.cash-movements.store');

    // Historique des mouvements manuels d'une session POS.
    Route::get(
        '/pos/sessions/{sessionId}/cash-movements',
        [PosCashMovementController::class, 'index']
    )
        ->whereNumber('sessionId')
        ->name('pos.cash-movements.index');

    // Rapport financier d'une session POS clôturée.
    Route::get(
        '/pos/sessions/{sessionId}/closing-report',
        [PosClosingReportController::class, 'show']
    )
        ->whereNumber('sessionId')
        ->name('pos.sessions.closing-report');

    // Consulter les paramètres documentaires.
    Route::get(
        '/store/document-settings',
        [StoreDocumentSettingController::class, 'show']
    )->name('store.document-settings.show');

    // Modifier les paramètres documentaires.
    Route::patch(
        '/store/document-settings',
        [StoreDocumentSettingController::class, 'update']
    )->name('store.document-settings.update');
});

/*
|--------------------------------------------------------------------------
| Invitations des employés ZEMBOA
|--------------------------------------------------------------------------
| Routes publiques indépendantes des boutiques.
*/

Route::get(
    '/employee-invitations/{token}',
    [EmployeeInvitationController::class, 'show']
)->name('employee.invitation.form');

Route::post(
    '/employee-invitations/{token}',
    [EmployeeInvitationController::class, 'accept']
)
    ->middleware('throttle:6,1')
    ->name('employee.invitation.accept');

// Invitation des propriétaires de boutiques.

Route::get(
    '/owner-invitations/{token}',
    [OwnerInvitationController::class, 'show']
)->name('owner.invitation.form');

Route::post(
    '/owner-invitations/{token}',
    [OwnerInvitationController::class, 'accept']
)
    ->middleware('throttle:6,1')
    ->name('owner.invitation.accept');


/*
|--------------------------------------------------------------------------
| Catalogue public des boutiques ZEMBOA
|--------------------------------------------------------------------------
| Accessible sans authentification.
| Chaque requête est limitée à une boutique active.
*/

Route::prefix('shops/{shopSlug}')
    ->middleware([
        'public.tenant',
        \App\Http\Middleware\ApplyStoreLocale::class,
    ])
    ->name('public.shops.')
    ->group(function () {

        Route::get(
            '/',
            [PublicCatalogController::class, 'shop']
        )->name('show');

        Route::get(
            '/categories',
            [PublicCatalogController::class, 'categories']
        )->name('categories');

        Route::get(
            '/products',
            [PublicCatalogController::class, 'products']
        )->name('products');

        Route::get(
            '/products/{product}',
            [PublicCatalogController::class, 'product']
        )
            ->whereNumber('product')
            ->name('products.show');

        // Panier client public

        Route::get(
            '/cart',
            [PublicCartController::class, 'index']
        )->name('cart.index');

        Route::post(
            '/cart/items',
            [PublicCartController::class, 'store']
        )->name('cart.store');

        Route::patch(
            '/cart/items/{variantId}',
            [PublicCartController::class, 'update']
        )
            ->whereNumber('variantId')
            ->name('cart.update');

        Route::delete(
            '/cart/items/{variantId}',
            [PublicCartController::class, 'destroy']
        )
            ->whereNumber('variantId')
            ->name('cart.destroy');

        Route::delete(
            '/cart',
            [PublicCartController::class, 'clear']
        )->name('cart.clear');

        Route::post(
            '/checkout',
            [PublicCheckoutController::class, 'store']
        )
            ->block(30, 10)
            ->name('checkout.store');
    });
