<?php

namespace Modules\EIS\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Services\EisSubscriptionAccess;
use Modules\EIS\Services\Terminal\EisTerminalActivationService;

class SyncBusinessTerminalsLatestConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 1;

    public function __construct(
        public int $businessId
    ) {
    }

    public function handle(EisTerminalActivationService $activationService, EisSubscriptionAccess $subscriptionAccess): void
    {
        if (!$subscriptionAccess->allows($this->businessId)) {
            Log::info('EIS business terminal latest config sync skipped because the business package does not include EIS.', [
                'business_id' => $this->businessId,
            ]);

            return;
        }

        $terminalIds = EisSetting::where('business_id', $this->businessId)
            ->pluck('id');

        foreach ($terminalIds as $terminalId) {
            try {
                $result = $activationService->getLatestConfig($this->businessId, (int) $terminalId);

                if (empty($result['success'])) {
                    Log::warning('EIS terminal latest config sync failed during first login of the day', [
                        'business_id' => $this->businessId,
                        'eis_setting_id' => (int) $terminalId,
                        'message' => $result['message'] ?? $result['msg'] ?? 'Unknown error',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('EIS terminal latest config sync failed during first login of the day', [
                    'business_id' => $this->businessId,
                    'eis_setting_id' => (int) $terminalId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}