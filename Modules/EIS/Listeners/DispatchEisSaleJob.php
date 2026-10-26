<?php

namespace Modules\EIS\Listeners;

use Modules\EIS\Jobs\SubmitOfflineSalesJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\EIS\Events\SaleCompleted;
use Modules\EIS\Services\Sales\SaleSubmissionRule;
use Modules\EIS\Services\Sales\SaleSubmissionService;

class DispatchEisSaleJob
{
    public function handle(SaleCompleted $event): void
    {
        if (! SaleSubmissionRule::qualifies($event->transaction)) {
            Log::info('Queued EIS submission skipped for an unpaid or non-final sale.', [
                'transaction_id' => $event->transaction->id,
            ]);
            return;
        }

        DB::afterCommit(function () use ($event) {
            $sale = app(SaleSubmissionService::class)->prepare($event->transaction);
            if ($sale->status === 'submitted') {
                return;
            }

            $connection = config('queue.default');
            if (in_array(config("queue.connections.{$connection}.driver"), ['sync', 'null'], true)) {
                $connection = 'database';
            }

            SubmitOfflineSalesJob::dispatch(
                (int) $event->transaction->id,
                (int) $event->transaction->business_id
            )->onConnection($connection)->onQueue('eis-submission')->afterCommit();
            Log::info('EIS sale tracking created and submission queued after transaction commit.', [
                'transaction_id' => $event->transaction->id,
                'business_id' => $event->transaction->business_id,
                'eis_sale_id' => $sale->id,
            ]);
        });
    }
}