<?php

namespace App\Console\Commands;


use App\Actions\Payments\ConfirmPayment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentConcurrencyWorker extends Command
{
    protected $signature = 'test:payment-concurrency-worker
        {paymentId : Identifiant du paiement}
        {cashierId : Identifiant du caissier}
        {--balance-due-at= : Date limite du solde}';

    protected $description =
        'Confirmer un paiement dans le test de concurrence MySQL';

    public function handle(): int
    {
        // 1. Interdire toute exécution hors des tests.
        if (
            !app()->environment('testing') ||
            config('database.default') !== 'mysql' ||
            config('database.connections.mysql.database')
                !== 'zemboa_concurrency_test' ||
            config('database.connections.mysql.username')
                !== 'zemboa_test'
        ) {
            $this->error('ENVIRONNEMENT DE TEST NON AUTORISÉ.');

            return self::FAILURE;
        }

        try {
            // 2. Vérifier la connexion MySQL réelle.
            $identity = DB::selectOne(
                'SELECT DATABASE() AS db,
                        CURRENT_USER() AS account'
            );

            if (
                $identity->db !== 'zemboa_concurrency_test' ||
                $identity->account !== 'zemboa_test@127.0.0.1'
            ) {
                $this->error('CONNEXION MYSQL NON AUTORISÉE.');

                return self::FAILURE;
            }

            // 3. Lire les paramètres du processus.
            $paymentId = filter_var(
                $this->argument('paymentId'),
                FILTER_VALIDATE_INT
            );

            $cashierId = filter_var(
                $this->argument('cashierId'),
                FILTER_VALIDATE_INT
            );

            if (
                $paymentId === false ||
                $cashierId === false ||
                $paymentId <= 0 ||
                $cashierId <= 0
            ) {
                $this->error('Identifiants invalides.');

                return self::FAILURE;
            }

            // 4. Identifier le caissier.
            $cashier = User::findOrFail($cashierId);

            // 5. Activer le contexte de sa boutique.
            app(TenantContext::class)->setFromUser($cashier);

            // 6. Authentifier le caissier dans ce processus.
            Auth::setUser($cashier);

            // 7. Demander la confirmation du paiement.
            $payment = app(ConfirmPayment::class)->execute(
                paymentId: $paymentId,
                userId: $cashier->id,
                balanceDueAt: $this->option('balance-due-at') ?: null
            );

            // Confirmation réussie.
            $this->info('RESULT=CONFIRMED');
            $this->line('PAYMENT_ID=' . $payment->id);

            return self::SUCCESS;

        } catch (ValidationException | AuthorizationException $exception) {
            // Refus métier attendu :
            // paiement excessif, déjà traité, permission absente...
            $this->warn('RESULT=REJECTED');

            return self::FAILURE;

        } catch (Throwable $exception) {
            // Une erreur technique n'est pas un refus métier.
            $this->error('RESULT=ERROR');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
