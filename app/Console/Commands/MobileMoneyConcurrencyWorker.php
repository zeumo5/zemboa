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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MobileMoneyConcurrencyWorker extends Command
{
    protected $signature =
        'test:mobile-money-concurrency-worker
        {paymentId : Identifiant du paiement}
        {userId : Utilisateur de la boutique}';

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
            // 2. Vérifier la connexion MySQL réellement utilisée.
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

            // 3. Valider les identifiants.
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

            // 4. Initialiser le contexte de la boutique.
            $user = User::query()
                ->findOrFail($userId);

            app(TenantContext::class)->setFromUser($user);

            Auth::setUser($user);

            // 5. Retrouver le paiement et son compte marchand.
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->firstOrFail();

            $account = StorePaymentAccount::query()
                ->whereKey($payment->store_payment_account_id)
                ->where('store_id', $payment->store_id)
                ->where('method', $payment->method)
                ->firstOrFail();

            // 6. Préparer une réponse simulée.
            // Ce code est strictement réservé aux tests.
            $result = new MobileMoneyVerificationResult(
                status: 'SUCCESS',
                reference: (string) $payment->reference,
                amount: (string) $payment->amount,
                currency: 'XAF',
                provider: $payment->method,
                providerTransactionId:
                    'FAKE-CONCURRENCY-' . $payment->id,
                recipientMerchantAccountId:
                    (string) $account->merchant_account_id
            );

            // 7. Injecter le simulateur uniquement
            // dans ce processus de test isolé.
            app()->instance(
                MobileMoneyVerifier::class,
                new FakeMobileMoneyVerifier($result)
            );

            // 8. Tenter la confirmation atomique.
            $confirmed = app(
                ConfirmMobileMoneySuccessAtomically::class
            )->execute($paymentId);

            $this->info('RESULT=CONFIRMED');

            $this->line(
                'PAYMENT_ID=' . $confirmed->id
            );

            return self::SUCCESS;

        } catch (ValidationException $exception) {

            // Refus métier attendu en cas de concurrence.
            $this->warn('RESULT=REJECTED');

            return self::FAILURE;

        } catch (Throwable $exception) {

            // Une erreur technique ne doit pas être
            // considérée comme un refus normal.
            $this->error('RESULT=ERROR');

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
