<?php

namespace App\Http\Controllers;

class PesaPalController extends Controller
{
    //Used for pesapal payment confirmation for superadmin module.
    public function pesaPalPaymentConfirmation($transaction_id, $status, $payment_method, $merchant_reference)
    {
        $class = new \Modules\Superadmin\Http\Controllers\PesaPalController();
        \Log::info('PesaPal payment confirmation received', [
            'status_received' => is_scalar($status),
            'payment_method_received' => is_scalar($payment_method),
            'transaction_id_present' => $transaction_id !== '',
        ]);

        $class->pesaPalPaymentConfirmation($transaction_id, $status, $payment_method, $merchant_reference);
    }
}
