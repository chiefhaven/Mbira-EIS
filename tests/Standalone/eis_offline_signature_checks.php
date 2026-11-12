<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

use App\Transaction;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Models\EisTaxRate;
use Modules\EIS\Services\Sales\InvoiceNumberGenerator;
use Modules\EIS\Services\Sales\OfflineSignatureService;
use Modules\EIS\Services\Sales\SaleTransformer;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('log', new Psr\Log\NullLogger());
$app->instance('config', new Illuminate\Config\Repository(['eis' => ['environment' => 'development']]));
$signer = new OfflineSignatureService();
$transformer = new SaleTransformer(new EisTaxRate(), $signer, new InvoiceNumberGenerator());
$setting = new EisSetting();
$setting->tpin = '99999999';
$setting->secret_key = 'synthetic-signing-check-key';
$transaction = new Transaction();
$checks = 0;
function signingCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

signingCheck($signer->computeHMACSHA256('Hi There', str_repeat(chr(0x0b), 20))
    === rtrim(strtr(base64_encode(hex2bin('b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7')), '+/', '-_'), '='),
    'HMAC-SHA256 matches RFC 4231 test case 1 in the MRA Base64URL format');
foreach (['2026-11-10T00:30:00+02:00', '2026-11-10T23:30:00-03:00', '2026-11-10T12:00:00Z'] as $date) {
    $number = $signer->generateCombinedString(12345, 5, $signer->toJulianDate($date), 10);
    $payload = [
        'invoiceHeader' => ['invoiceNumber' => $number, 'invoiceDateTime' => $date],
        'invoiceLineItems' => [['quantity' => 1.5], ['quantity' => 2]],
        'invoiceSummary' => ['invoiceTotal' => 8500, 'totalVAT' => 1265.96],
    ];
    $data = $transformer->generateOfflineValidationData($setting, $transaction, $payload);
    signingCheck($data['combinedString'] === $number, 'Signed TI uses fiscal taxpayer ID, not seller TIN');
    signingCheck($data['julianDate'] === 2461355, 'Invoice local calendar date survives timezone offsets');
    signingCheck($data['param'] === 'TI='.$number.'&N=2&I=8500&V=1265.96&T=JY6r',
        'Canonical signing parameters use line count rather than summed fractional quantities');
    signingCheck($data['offlineDataSignature'] === rtrim(strtr(base64_encode(hash_hmac(
        'sha256', $data['param'], 'synthetic-signing-check-key', true
    )), '+/', '-_'), '='), 'Signature uses exact canonical parameter bytes and unpadded Base64URL');
    signingCheck((bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $data['offlineDataSignature']),
        'Signature has no standard Base64 punctuation, padding or percent escapes');
    signingCheck($signer->validateOfflineSignature($data['param'], $data['offlineDataSignature'], $setting->secret_key)
        && !$signer->validateOfflineSignature($data['param'].'&N=3', $data['offlineDataSignature'], $setting->secret_key),
        'Generated signatures verify and reject modified signing parameters');
    signingCheck(str_ends_with($data['validationURL'], '?'.$data['param'].'&S='.$data['offlineDataSignature']),
        'Validation URL carries the identical signed parameters and signature');
    signingCheck(str_starts_with($data['validationURL'], 'https://dev-eis-portal.mra.mw/ReceiptValidation/Validate/?'),
        'Development configuration uses the sandbox portal');
}
$app['config']->set('eis.environment', 'production');
$data = $transformer->generateOfflineValidationData($setting, $transaction, $payload);
signingCheck(str_starts_with($data['validationURL'], 'https://eis-portal.mra.mw/ReceiptValidation/Validate/?'),
    'Production configuration uses the production portal');
signingCheck(str_starts_with($signer->generateValidationURL('12345', 5, [
    'transactiondate' => $date, 'transactionCount' => 10,
], 'synthetic-key', 'https://example.invalid/validate/'), 'https://example.invalid/validate/?'),
    'Explicit validation URL argument is honored');

foreach ([
    ['invoiceHeader' => ['invoiceNumber' => 'invalid']],
    ['invoiceHeader' => ['invoiceDateTime' => null]],
    ['invoiceHeader' => ['invoiceDateTime' => '2026-11-11T12:00:00Z']],
    ['invoiceLineItems' => [['quantity' => -1]]],
    ['invoiceLineItems' => [['quantity' => INF]]],
    ['invoiceSummary' => ['totalVAT' => null]],
    ['invoiceSummary' => ['invoiceTotal' => INF]],
    ['invoiceSummary' => ['totalVAT' => 9000]],
] as $change) {
    $invalid = array_replace_recursive($payload, $change);
    if (isset($change['invoiceLineItems'])) {
        $invalid['invoiceLineItems'] = $change['invoiceLineItems'];
    }
    try {
        $transformer->generateOfflineValidationData($setting, $transaction, $invalid);
        throw new RuntimeException('Invalid signing input must be rejected');
    } catch (InvalidArgumentException|UnexpectedValueException $e) {
        signingCheck(true, 'Invalid identity, fiscal date or quantity fails explicitly');
    }
}
$setting->secret_key = '';
try {
    $transformer->generateOfflineValidationData($setting, $transaction, $payload);
    throw new RuntimeException('Empty key must not generate a signature');
} catch (InvalidArgumentException $e) {
    signingCheck(true, 'Empty signing key is rejected');
}
$setting->secret_key = 'synthetic-signing-check-key';
$app['config']->set('eis.environment', 'unsupported');
try {
    $transformer->generateOfflineValidationData($setting, $transaction, $payload);
    throw new RuntimeException('Unsupported environment must not select a portal silently');
} catch (InvalidArgumentException $e) {
    signingCheck(true, 'Unsupported EIS environment is rejected');
}
echo "PASS: {$checks} offline signature checks (synthetic credentials, no network)\n";
