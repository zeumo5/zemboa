<?php
namespace App\Console\Commands;


use App\Actions\Payments\ConfirmMobileMoneySuccessAtomically;
use App\Models\Payment;
use App\Models\StorePaymentAccount;
use App\Models\User;
use App\Payments\FakeMobileMoneyVerifier;
use App\Payments\MobileMoneyVerifier;
use App\Payments\MobileMoneyVerificationResult;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MobileMoneyConcurrencyWorker extends Command
{
    protected $signature = 'test:mobile-money-concurrency-worker
        {paymentId : Identifiant du paiement}
        {userId : Utilisateur de la boutique}
        {--external-tx-id= : Identifiant externe fictif pour les tests}';

    protected $description =
        'Tester la concurrence Mobile Money sur MySQL isolé';

    public function handle(): int
    {
        // 1. Refuser toute exécution hors de la base de test.
        if (
            !app()->environment('testing') ||
            config('database.default') !== 'mysql' ||
            config('database.connections.mysql.database')
                !== 'zemboa_concurrency_test' ||
            config('database.connections.mysql.username')
                !== 'zemboa_test'
        ) {
            $this->error('ENVIRONNEMENT NON AUTORISÉ.');

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

            // 3. Vérifier les identifiants reçus.
            $paymentId = filter_var(
                $this->argument('paymentId'),
                FILTER_VALIDATE_INT
            );

            $userId = filter_var(
                $this->argument('userId'),
                FILTER_VALIDATE_INT
            );

            if (
                $paymentId === false ||
                $userId === false ||
                $paymentId <= 0 ||
                $userId <= 0
            ) {
                $this->error('IDENTIFIANTS INVALIDES.');

                return self::FAILURE;
            }

            // 4. Identifier l'utilisateur.
            $user = User::query()->findOrFail($userId);

            // 5. Activer le contexte de sa boutique.
            app(TenantContext::class)->setFromUser($user);

            Auth::setUser($user);

            // 6. Retrouver le paiement.
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->firstOrFail();

            // 7. Retrouver son compte marchand.
            $account = StorePaymentAccount::query()
                ->whereKey($payment->store_payment_account_id)
                ->where('store_id', $payment->store_id)
                ->where('method', $payment->method)
                ->firstOrFail();

            // 8. Préparer l'identifiant externe fictif.
            // L'option permet aux deux processus
            // d'utiliser le même identifiant en test.
            $externalTransactionId =
                $this->option('external-tx-id')
                ?: 'FAKE-CONCURRENCY-' . $payment->id;

            // 9. Simuler une réponse SUCCESS.
            $result = new MobileMoneyVerificationResult(
                status: 'SUCCESS',
                reference: (string) $payment->reference,
                amount: (string) $payment->amount,
                currency: 'XAF',
                provider: $payment->method,
                providerTransactionId: $externalTransactionId,
                recipientMerchantAccountId:
                    (string) $account->merchant_account_id
            );

            // 10. Injecter le simulateur uniquement
            // dans le processus de test isolé.
            app()->instance(
                MobileMoneyVerifier::class,
                new FakeMobileMoneyVerifier($result)
            );

            // 11. Tenter la confirmation atomique.
            $confirmed = app(
                ConfirmMobileMoneySuccessAtomically::class
            )->execute($paymentId);

            $this->info('RESULT=CONFIRMED');

            $this->line(
                'PAYMENT_ID=' . $confirmed->id
            );

            return self::SUCCESS;

        } catch (ValidationException $exception) {

            // Refus métier attendu.
            $this->warn('RESULT=REJECTED');

            return self::FAILURE;

        } catch (UniqueConstraintViolationException $exception) {

            // Refus attendu uniquement pour un doublon
            // du registre central Mobile Money.
            if (!str_contains(
                $exception->getMessage(),
                'mm_claim_provider_tx_unique'
            )) {
                $this->error('RESULT=ERROR');
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->warn('RESULT=REJECTED');

            return self::FAILURE;

        } catch (Throwable $exception) {

            // Une erreur technique n'est pas
            // un refus métier normal.
            $this->error('RESULT=ERROR');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
