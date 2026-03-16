<?php
// telebirr_verify.php
//
// Call like:
//   https://fetan.net/telebirr/telebirr_verify.php?ref=XYZ&key=YOUR_SECRET
//
// This version mirrors your Python logic:
// - fetch_receipt_raw()
// - generate_reference_candidates() with:
//      1) only 0/O swaps
//      2) only 1/I swaps
//      3) both 0/O and 1/I
// - find_receipt_with_variants(): tries raw ref first, then candidates
// - returns JSON: { ok, exists, data: { reference_used, ... } }

header('Content-Type: application/json; charset=utf-8');

// ========= CONFIG =========

// same secret as settings.MICROSERVICE_SECRET_KEY
$SECRET_KEY = 'CHANGE_ME';

// Telebirr URL template
define('TELEBIRR_URL_TEMPLATE', 'https://transactioninfo.ethiotelecom.et/receipt/%s');

// ==========================

/**
 * Fetch and parse a Telebirr receipt for a single reference.
 *
 * Returns:
 *   associative array:
 *     [
 *       'reference_number'  => string,
 *       'credited_name'     => string,
 *       'credited_account'  => string,
 *       'status'            => string,
 *       'settled_amount'    => string,   // e.g. "50.00"
 *       'payment_date'      => string,   // "27-11-2025 17:19:18"
 *     ]
 *   or null on failure / invalid ref.
 */
function fetch_receipt_raw($referenceNumber)
{
    $url         = sprintf(TELEBIRR_URL_TEMPLATE, urlencode($referenceNumber));
    $maxAttempts = 5;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'TelebirrProxy/1.0',
        ]);

        $html       = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo      = curl_errno($ch);
        $err        = curl_error($ch);

        curl_close($ch);

        if ($errNo !== 0) {
            // network error
            return null;
        }

        if ($statusCode == 500) {
            // retry on 500
            if ($attempt < $maxAttempts - 1) {
                sleep(1);
                continue;
            }
            return null;
        }

        if ($statusCode != 200) {
            return null;
        }

        if ($html === null) {
            return null;
        }

        // "This request is not correct" => invalid reference
        if (strpos($html, 'This request is not correct') !== false) {
            return null;
        }

        // --- parse HTML similar to BeautifulSoup get_text() ---

        // Convert <br> and </p> to newlines for better splitting
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n", $html);

        $text  = html_entity_decode(strip_tags($html));
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));

        // helper: line after label
        $after = function ($label) use ($lines) {
            $n = count($lines);
            for ($i = 0; $i < $n; $i++) {
                if (strpos($lines[$i], $label) !== false) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if (trim($lines[$j]) !== '') {
                            return trim($lines[$j]);
                        }
                    }
                }
            }
            return null;
        };

        $creditedName    = $after("Credited Party name");
        $creditedAccount = $after("Credited party account no");
        $status          = $after("transaction status");

        $paymentDateRaw = null;
        $amountRaw      = null;

        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (strpos($lines[$i], "Settled Amount") !== false) {
                if ($i + 2 < $n) {
                    $paymentDateRaw = trim($lines[$i + 2]);
                }
                if ($i + 3 < $n) {
                    $amountRaw = trim($lines[$i + 3]);
                }
                break;
            }
        }

        if (!$creditedName || !$creditedAccount || !$status || !$paymentDateRaw || !$amountRaw) {
            return null;
        }

        // numeric part from amount
        if (!preg_match('/([\d.,]+)/', $amountRaw, $m)) {
            return null;
        }
        $amountStr = str_replace(',', '', $m[1]);

        return [
            'reference_number'  => $referenceNumber,
            'credited_name'     => $creditedName,
            'credited_account'  => $creditedAccount,
            'status'            => $status,
            'settled_amount'    => $amountStr,
            'payment_date'      => $paymentDateRaw,  // "27-11-2025 17:19:18"
        ];
    }

    return null;
}

/**
 * Generate reference variants by flipping 0/O and 1/I.
 * Mirrors your Python generate_reference_candidates:
 *
 *  - It does NOT include the original reference.
 *  - First: 0/O-only combinations
 *  - Second: 1/I-only combinations
 *  - Third: both 0/O and 1/I combinations
 */
function generate_reference_candidates($raw, $maxVariants = 512)
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
            $out[]    = $x;
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

    // 1) only 0/O swaps
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

    // 2) only 1/I swaps
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

    // 3) 0/O + 1/I combined
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
 * Try original reference first, then all variants.
 *
 * Returns: [receiptArray|null, usedReference|null]
 */
function find_receipt_with_variants_php($rawReference)
{
    // try original first
    $receipt = fetch_receipt_raw($rawReference);
    if ($receipt !== null) {
        return [$receipt, $rawReference];
    }

    // then candidates in priority order
    $candidates = generate_reference_candidates($rawReference);
    foreach ($candidates as $cand) {
        $receipt = fetch_receipt_raw($cand);
        if ($receipt !== null) {
            return [$receipt, $cand];
        }
    }

    return [null, null];
}

/* ===================== CONTROLLER ===================== */

// check secret key
$key = isset($_GET['key']) ? trim($_GET['key']) : '';
if ($key !== $SECRET_KEY) {
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

// main logic: original ref + variants
list($receipt, $usedRef) = find_receipt_with_variants_php($rawRef);

if ($receipt === null || $usedRef === null) {
    echo json_encode([
        'ok'     => true,
        'exists' => false,
        'data'   => null,
    ]);
    exit;
}

// success: return JSON – similar to your simple PHP, but with `reference_used`
echo json_encode([
    'ok'     => true,
    'exists' => true,
    'data'   => [
        'reference_submitted' => $rawRef,
        'reference_used'      => $usedRef,
        'credited_name'       => $receipt['credited_name'],
        'credited_account'    => $receipt['credited_account'],
        'status'              => $receipt['status'],
        'payment_date'        => $receipt['payment_date'],   // "27-11-2025 17:19:18"
        'settled_amount'      => $receipt['settled_amount'], // "50.00"
    ],
]);
