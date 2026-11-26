<?php

namespace Modules\EIS\Services\Sales;

use App\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EIS\Exceptions\EisSaleException;
use Modules\EIS\Exceptions\TerminalBlockedException;
use Modules\EIS\Events\SaleCompleted;

/**
 * Decides which sales initiate an EIS submission.
 *
 * A final sell initiates it when it is paid in full, by cash or any other method.
 * Drafts, quotations, suspended sales, credit sales and partially paid sales (partial cash included) do not,
 * so they are neither submitted nor left behind as pending EIS rows (pending rows count against the offline
 * limits). Adding/editing payments or settling contact dues initiates submission when a sale becomes
 * fully paid.
 */
class SaleSubmissionRule
{
    /**
     * Ask EIS to submit a sale that was just saved.
     *
     * When called inside a transaction, submit immediately if EIS is online. A non-200 EIS response
     * is thrown to the caller so the enclosing sale/payment transaction can roll back. Offline or
     * indeterminate submissions remain queued after commit.
     */
    public static function initiate(Transaction $transaction, bool $originalCheckout = false): ?string
    {
        $submissionError = null;
        if (DB::transactionLevel() > 0) {
            try {
                $onlineResult = static::submitOnline($transaction);
            } catch (TerminalBlockedException $exception) {
                if ($originalCheckout) {
                    throw $exception;
                }

                return $exception->getMessage();
            }
            if ($onlineResult === true) {
                return null;
            }
            if (is_string($onlineResult)) {
                $submissionError = $onlineResult;
            }
        }

        DB::afterCommit(function () use ($transaction, $originalCheckout) {
            try {
                // Payment utilities save a separate model; the caller may still hold an unpaid snapshot.
                $committed = $transaction->newQuery()
                    ->where('business_id', $transaction->business_id)
                    ->findOrFail($transaction->id);
                if (!static::qualifies($committed)) {
                    return;
                }

                if (!app(\Modules\EIS\Services\EisSubscriptionAccess::class)
                    ->allows((int) $committed->business_id)) {
                    return;
                }

                event(new SaleCompleted($committed, $originalCheckout));
            } catch (\Throwable $e) {
                Log::error('EIS submission failed after sale commit', [
                    'transaction_id' => $transaction->id,
                    'message' => $e->getMessage(),
                ]);
            }
        });

        return $submissionError;
    }

    private static function submitOnline(Transaction $transaction): bool|string|null
    {
        try {
            $transaction = $transaction->newQuery()
                ->where('business_id', $transaction->business_id)
                ->findOrFail($transaction->id);
            if (!static::qualifies($transaction)
                || !app(\Modules\EIS\Services\EisSubscriptionAccess::class)
                    ->allows((int) $transaction->business_id)) {
                return null;
            }

            $settings = \Modules\EIS\Services\Terminal\TerminalContext::forSale($transaction);
            if (!app(\Modules\EIS\Services\Http\EisHealthService::class)
                ->isOnline((int) $transaction->business_id, (string) $settings->jwt_token)) {
                return null;
            }

            $sale = app(SaleSubmissionService::class)->submit($transaction, $settings);
            return $sale->status === 'submitted' ? true : ($sale->error_message ?: null);
        } catch (TerminalBlockedException $exception) {
            throw $exception;
        } catch (EisSaleException $exception) {
            if ($exception->requiresCheckoutRollback()
                || ($exception->httpStatus !== null && $exception->httpStatus !== 200)) {
                throw $exception;
            }

            Log::warning('Online EIS submission did not complete; retaining sale for retry', [
                'transaction_id' => $transaction->id,
                'http_status' => $exception->httpStatus,
            ]);
            return $exception->getMessage();
        } catch (\Throwable $exception) {
            Log::warning('Online EIS submission could not be completed; retaining sale for retry', [
                'transaction_id' => $transaction->id,
                'exception' => get_class($exception),
            ]);
            return null;
        }
    }

    /**
     * True when the sale must be submitted to EIS: a final sell that is paid in full.
     */
    public static function qualifies(Transaction $transaction): bool
    {
        return static::isFinalSell($transaction) && static::isPaid($transaction);
    }

    /**
     * True when the sale is paid in full (payment_status 'paid', which already nets off the change handed back).
     * A partial payment is not, whatever the payment method.
     */
    public static function isPaid(Transaction $transaction): bool
    {
        return $transaction->payment_status === 'paid';
    }

    /**
     * The same condition as isPaid(), as a constraint on a Transaction query.
     */
    public static function constrainPaid(Builder $query): Builder
    {
        return $query->where('payment_status', 'paid');
    }

    protected static function isFinalSell(Transaction $transaction): bool
    {
        return $transaction->type === 'sell' && $transaction->status === 'final';
    }
}
