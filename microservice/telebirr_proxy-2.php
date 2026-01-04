<?php
/**
 * Telebirr receipt proxy – PHP version of your Django view.
 *
 * - Validates ?key= against MICROSERVICE_SECRET_KEY
 * - Accepts ?ref= raw reference
 * - Tries the raw reference first
 * - Then tries variants with 0/O and 1/I swaps
 * - Scrapes Telebirr receipt HTML and returns JSON
 */

// =================== CONFIG ===================

const TELEBIRR_RECEIPT_URL_TEMPLATE = 'https://transactioninfo.ethiotelecom.et/receipt/{reference}';

// Same as settings.MICROSERVICE_SECRET_KEY in Django
const MICROSERVICE_SECRET_KEY = 'CHANGE-ME';

// Timezone for Telebirr timestamps (Ethiopia)
const TELEBIRR_TIMEZONE = 'Africa/Addis_Ababa';

// Max variants to generate for reference guessing
const MAX_VARIANTS = 512;

// ==============================================

header('Content-Type: application/json; charset=utf-8');

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'ok'    => false,
        'error' => 'method_not_allowed',
        'message' => 'Only GET is allowed',
    ]);
    exit;
}

/**
 * Simple HTTP GET with cURL.
 */
function http_get($url, $timeoutSeconds = 15)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_HEADER         => true,   // we want headers + body
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [null, null, $err];
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);

    return [$statusCode, $body, null];
}

/**
 * Fetch and parse a Telebirr receipt for a single reference.
 * Returns associative array or null.
 */
function fetch_receipt_raw($referenceNumber)
{
    $url         = str_replace('{reference}', urlencode($referenceNumber), TELEBIRR_RECEIPT_URL_TEMPLATE);
    $maxAttempts = 5;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        list($statusCode, $body, $err) = http_get($url, 15);

        if ($err !== null) {
            // network / cURL error
            return null;
        }

        if ($statusCode == 500) {
            if ($attempt < $maxAttempts - 1) {
                sleep(1);
                continue;
            }
            return null;
        }

        if ($statusCode != 200) {
            return null;
        }

        // --- Parse HTML into lines (similar to BeautifulSoup get_text) ---

        // Try to preserve some structure by turning <br> and </p> into newlines
        $html = $body;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n", $html);

        // Strip tags to plain text
        $text = strip_tags($html);
        $text = trim($text);

        // Split into non-empty lines
        $linesRaw = preg_split('/\r\n|\r|\n/', $text);
        $lines = [];
        foreach ($linesRaw as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        // Quick sanity check (Telebirr sometimes responds with this)
        $bigText = implode("\n", $lines);
        if (strpos($bigText, 'This request is not correct') !== false) {
            return null;
        }

        // Helper: find the first non-empty line after a label
        $after = function ($label) use ($lines) {
            $count = count($lines);
            for ($i = 0; $i < $count; $i++) {
                if (strpos($lines[$i], $label) !== false) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $candidate = trim($lines[$j]);
                        if ($candidate !== '') {
                            return $candidate;
                        }
                    }
                }
            }
            return null;
        };

        $creditedName    = $after('Credited Party name');
        $creditedAccount = $after('Credited party account no');
        $status          = $after('transaction status');

        $paymentDateRaw = null;
        $amountRaw      = null;

        // Find "Settled Amount" line, then read the next two lines (date, amount)
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (strpos($lines[$i], 'Settled Amount') !== false) {
                if ($i + 2 < $n) {
                    $paymentDateRaw = $lines[$i + 2];
                }
                if ($i + 3 < $n) {
                    $amountRaw = $lines[$i + 3];
                }
                break;
            }
        }

        if (!$creditedName || !$creditedAccount || !$status || !$paymentDateRaw || !$amountRaw) {
            return null;
        }

        // Extract numeric part from amount (e.g., "1,234.56 ETB")
        if (!preg_match('/([\d.,]+)/', $amountRaw, $m)) {
            return null;
        }
        $amountStr = str_replace(',', '', $m[1]); // keep as string to avoid float issues

        // Parse datetime: "25-11-2025 09:06:37"
        $tz      = new DateTimeZone(TELEBIRR_TIMEZONE);
        $payment = DateTime::createFromFormat('d-m-Y H:i:s', trim($paymentDateRaw), $tz);
        if ($payment === false) {
            return null;
        }

        // Normalize to ISO8601 (includes offset, like 2025-11-25T09:06:37+03:00)
        $paymentIso = $payment->format(DateTime::ATOM);

        return [
            'reference_number' => $referenceNumber,
            'credited_name'    => $creditedName,
            'credited_account' => $creditedAccount,
            'status'           => $status,
            'settled_amount'   => $amountStr,
            'payment_datetime_iso' => $paymentIso,
        ];
    }

    return null;
}

/**
 * Generate reference variants by flipping 0/O and 1/I.
 * Mirrors your Python generate_reference_candidates.
 */
function generate_reference_candidates($raw, $maxVariants = MAX_VARIANTS)
{
    $clean = strtoupper(str_replace([' ', '-'], '', $raw));
    $chars = preg_split('//u', $clean, -1, PREG_SPLIT_NO_EMPTY);
    $len   = count($chars);

    $idx_0o = [];
    $idx_1i = [];

    for ($i = 0; $i < $len; $i++) {
        if ($chars[$i] === '0' || $chars[$i] === 'O') {
            $idx_0o[] = $i;
        }
        if ($chars[$i] === '1' || $chars[$i] === 'I') {
            $idx_1i[] = $i;
        }
    }

    $seen = [];
    $out  = [];

    $add = function ($x) use (&$seen, &$out, $maxVariants) {
        if (count($out) >= $maxVariants) {
            return;
        }
        if (!isset($seen[$x])) {
            $seen[$x] = true;
            $out[] = $x;
        }
    };

    $flip = function ($c) {
        switch ($c) {
            case '0': return 'O';
            case 'O': return '0';
            case '1': return 'I';
            case 'I': return '1';
            default:  return $c;
        }
    };

    // 0/O swaps
    $n0 = count($idx_0o);
    $limit0 = (1 << $n0);
    for ($mask = 1; $mask < $limit0; $mask++) {
        $tmp = $chars;
        for ($b = 0; $b < $n0; $b++) {
            if ($mask & (1 << $b)) {
                $idx = $idx_0o[$b];
                $tmp[$idx] = $flip($tmp[$idx]);
            }
        }
        $add(implode('', $tmp));
    }

    // 1/I swaps
    $n1 = count($idx_1i);
    $limit1 = (1 << $n1);
    for ($mask = 1; $mask < $limit1; $mask++) {
        $tmp = $chars;
        for ($b = 0; $b < $n1; $b++) {
            if ($mask & (1 << $b)) {
                $idx = $idx_1i[$b];
                $tmp[$idx] = $flip($tmp[$idx]);
            }
        }
        $add(implode('', $tmp));
    }

    // 0/O + 1/I combined
    $both = array_merge($idx_0o, $idx_1i);
    $nb   = count($both);
    $limitBoth = (1 << $nb);
    for ($mask = 1; $mask < $limitBoth; $mask++) {
        $tmp = $chars;
        for ($b = 0; $b < $nb; $b++) {
            if ($mask & (1 << $b)) {
                $idx = $both[$b];
                $tmp[$idx] = $flip($tmp[$idx]);
            }
        }
        $add(implode('', $tmp));
    }

    return $out;
}

/**
 * Try raw reference, then variants.
 * Returns [receiptArray|null, usedReference|null]
 */
function find_receipt_with_variants($rawReference)
{
    $receipt = fetch_receipt_raw($rawReference);
    if ($receipt !== null) {
        return [$receipt, $rawReference];
    }

    $candidates = generate_reference_candidates($rawReference);
    foreach ($candidates as $cand) {
        $receipt = fetch_receipt_raw($cand);
        if ($receipt !== null) {
            return [$receipt, $cand];
        }
    }

    return [null, null];
}

// ================== CONTROLLER ==================

// Secret key validation
$key = isset($_GET['key']) ? trim($_GET['key']) : null;
if (!$key || $key !== MICROSERVICE_SECRET_KEY) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'error'   => 'forbidden',
        'message' => 'Invalid or missing secret key',
    ]);
    exit;
}

$rawRef = isset($_GET['ref']) ? trim($_GET['ref']) : '';

if ($rawRef === '') {
    echo json_encode([
        'ok'    => false,
        'error' => 'missing_ref',
    ]);
    exit;
}

list($receipt, $used) = find_receipt_with_variants($rawRef);

if ($receipt === null) {
    echo json_encode([
        'ok'     => true,
        'exists' => false,
        'data'   => null,
    ]);
    exit;
}

// Map to the same JSON shape as Django view
echo json_encode([
    'ok'     => true,
    'exists' => true,
    'data'   => [
        'reference_submitted' => $rawRef,
        'reference_used'      => $used,
        'credited_name'       => $receipt['credited_name'],
        'credited_account'    => $receipt['credited_account'],
        'status'              => $receipt['status'],
        'settled_amount'      => (string)$receipt['settled_amount'],
        'payment_datetime_iso'=> $receipt['payment_datetime_iso'],
    ],
]);
