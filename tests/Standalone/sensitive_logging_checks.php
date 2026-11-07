<?php

$root = dirname(__DIR__, 2);
$checks = 0;

function sensitiveLogCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$files = [
    'subscription' => file_get_contents($root.'/Modules/Superadmin/Http/Controllers/SubscriptionController.php'),
    'business' => file_get_contents($root.'/app/Http/Controllers/BusinessController.php'),
    'activation' => file_get_contents($root.'/Modules/EIS/Services/Terminal/EisTerminalActivationService.php'),
    'transaction' => file_get_contents($root.'/app/Utils/TransactionUtil.php'),
    'pesapal' => file_get_contents($root.'/app/Http/Controllers/PesaPalController.php'),
    'subscription_pesapal' => file_get_contents($root.'/Modules/Superadmin/Http/Controllers/PesaPalController.php'),
    'offline_signature' => file_get_contents($root.'/Modules/EIS/Services/Sales/OfflineSignatureService.php'),
    'sale_transformer' => file_get_contents($root.'/Modules/EIS/Services/Sales/SaleTransformer.php'),
    'invoice_generator' => file_get_contents($root.'/Modules/EIS/Services/Sales/InvoiceNumberGenerator.php'),
    'sale_response' => file_get_contents($root.'/Modules/EIS/Services/Sales/SaleResponseService.php'),
    'sale_client' => file_get_contents($root.'/Modules/EIS/Services/Http/EisSaleClient.php'),
    'offline_job' => file_get_contents($root.'/Modules/EIS/Jobs/SubmitOfflineSalesJob.php'),
];

$sensitivePatterns = [
    'subscription' => [
        "Log::info('OneKhusa Initiate Response', \$data)",
        "Log::error('OneKhusa: Missing paymentTransactionId', \$data)",
        "request()->all())",
        "Log::info('OneKhusa Webhook Received', \$payload)",
    ],
    'business' => ["['response' => \$data]"],
    'activation' => [
        "'response' => \$response->body()",
        "'response' => json_encode(\$response)",
        "'response' => \$responseData",
        "\$response->body(), \$response->status()",
        "'Failed to regenerate credentials: ' . \$response->body()",
        "throw new \\Exception(\$errorMessage,",
    ],
    'transaction' => ["Log::info('Transation: ', [\$transaction])"],
    'pesapal' => ["'transaction_id:'.\$transaction_id", "'status:'.\$status", "'payment_method:'.\$payment_method"],
    'subscription_pesapal' => ["'subscription transaction_id:'.\$transaction_id", "'status:'.\$status", "'payment_method:'.\$payment_method"],
    'offline_signature' => [
        "Log::debug('Offline signature generated'",
        "'signature' => \$offlineDataSignature",
        "'validation_url' => \$validationURL",
        "'trace' => \$e->getTraceAsString()",
    ],
    'sale_transformer' => [
        "'validation_url' => \$result['validationURL']",
        "'invoice_number' => \$eisInvoiceNumber",
        "'trace' => \$e->getTraceAsString()",
    ],
    'invoice_generator' => [
        "'invoice_number' => \$invoiceNumber",
        "'components' => [",
        "'trace' => \$e->getTraceAsString()",
    ],
    'sale_response' => [
        "'validation_url' => \$validationUrl",
        "'receipt_number' => \$receiptNumber",
        "'error' => \$errorMessage",
    ],
    'sale_client' => [
        "'response' => \$body",
        "'response' => \$responseData",
        "'invoice' => \$invoiceNumber",
    ],
    'offline_job' => ["'invoice_no' => \$transaction->invoice_no"],
];

foreach ($sensitivePatterns as $file => $patterns) {
    foreach ($patterns as $pattern) {
        sensitiveLogCheck(!str_contains($files[$file], $pattern), $file.' still contains sensitive logging: '.$pattern);
    }
}

sensitiveLogCheck(str_contains($files['subscription'], "'payment_transaction_id_present' =>")
    && str_contains($files['activation'], "'status' => \$response->status()")
    && str_contains($files['transaction'], "'qr_code_present' =>"),
    'Safe operational logging metadata is retained');

echo 'Sensitive logging checks passed: '.$checks." checks\n";
