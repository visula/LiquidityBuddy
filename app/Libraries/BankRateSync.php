<?php

namespace App\Libraries;

use Config\BankRates;
use Config\Services;

/**
 * Pulls bank products and interest rates from the providers in
 * app/Config/BankRates.php into the `bankproduct` catalog.
 *
 * Used by the Bank Rates page and by `php spark bankrates:sync`.
 * Every provider is synced on its own: a failing feed is recorded in
 * `bankprovidersync` and leaves its last good products in place.
 */
class BankRateSync
{
    public const TYPES = [
        'SAVINGS'       => 'Savings',
        'CURRENT'       => 'Current',
        'FIXED_DEPOSIT' => 'Fixed deposit',
        'OTHER'         => 'Other',
    ];

    /** Account types an account can have (bank products map onto the bank ones). */
    public const ACCOUNT_TYPES = [
        'CASH'          => 'Cash',
        'SAVINGS'       => 'Savings account',
        'CURRENT'       => 'Current account',
        'FIXED_DEPOSIT' => 'Fixed deposit',
        'CREDIT_CARD'   => 'Credit card',
        'WALLET'        => 'Mobile wallet',
        'OTHER'         => 'Other',
    ];

    /** Account types held at a bank (deposits go into them, withdrawals come out of them). */
    public const BANK_ACCOUNT_TYPES = ['SAVINGS', 'CURRENT', 'FIXED_DEPOSIT'];

    /** How money moves in or out of a bank account. */
    public const CHANNELS = [
        'OWN_ATM'      => 'Own bank ATM',
        'OTHER_ATM'    => 'Other bank ATM (LankaPay)',
        'COUNTER'      => 'Bank counter',
        'CDM'          => 'Cash deposit machine',
        'ONLINE_OWN'   => 'Online, same bank',
        'ONLINE_OTHER' => 'Online, other bank (CEFTS)',
    ];

    /** Channels that make sense for each kind of internal transaction, most common first. */
    public const TXN_CHANNELS = [
        'WITHDRAWAL' => ['OWN_ATM', 'OTHER_ATM', 'COUNTER'],
        'DEPOSIT'    => ['CDM', 'COUNTER'],
        'TRANSFER'   => ['ONLINE_OWN', 'ONLINE_OTHER', 'COUNTER'],
    ];

    protected BankRates $config;

    public function __construct(?BankRates $config = null)
    {
        $this->config = $config ?? config('BankRates');
    }

    protected function db()
    {
        return \Config\Database::connect();
    }

    // ------------------------------------------------------------------
    // Sync
    // ------------------------------------------------------------------

    /**
     * Sync every provider (or one). Unless $force, a provider synced less than
     * $minSyncInterval seconds ago is skipped. Returns one result per provider:
     * ['provider', 'name', 'status' => synced|skipped|failed, 'message', 'count'].
     */
    public function syncAll(?string $only = null, bool $force = false): array
    {
        $results = [];

        foreach ($this->config->providers as $key => $provider) {
            if ($only !== null && $key !== $only) {
                continue;
            }

            $results[] = $this->syncProvider((string) $key, $provider, $force);
        }

        if ($only !== null && $results === []) {
            $results[] = ['provider' => $only, 'name' => $only, 'status' => 'failed', 'message' => 'No provider with that key in app/Config/BankRates.php.', 'count' => 0];
        }

        return $results;
    }

    /** Sync when the catalog is older than $autoSyncAfter (used on page loads). Never throws. */
    public function syncIfStale(): void
    {
        if ($this->config->autoSyncAfter <= 0) {
            return;
        }

        try {
            $last = $this->db()->table('bankprovidersync')->select('providerkey, lastsyncat')->get()->getResultArray();
            $lastByKey = array_column($last, 'lastsyncat', 'providerkey');

            foreach (array_keys($this->config->providers) as $key) {
                if ((int) ($lastByKey[$key] ?? 0) < time() - $this->config->autoSyncAfter) {
                    $this->syncAll((string) $key);
                }
            }
        } catch (\Throwable $exception) {
            log_message('error', 'Bank rate auto sync skipped: {message}', ['message' => $exception->getMessage()]);
        }
    }

    public function syncProvider(string $key, array $provider, bool $force = false): array
    {
        $result = ['provider' => $key, 'name' => $provider['name'] ?? $key, 'status' => 'failed', 'message' => '', 'count' => 0];

        $last = $this->db()->table('bankprovidersync')->where('providerkey', $key)->get()->getRowArray();

        if (! $force && ! empty($last['lastsyncat']) && (int) $last['lastsyncat'] > time() - $this->config->minSyncInterval) {
            $result['status'] = 'skipped';
            $result['message'] = 'Synced ' . $this->ago((int) $last['lastsyncat']) . '; try again in a few minutes.';
            $result['count'] = (int) ($last['productcount'] ?? 0);

            return $result;
        }

        try {
            $payload = $this->fetch($provider);
            $items = $this->dig($payload, (string) ($provider['itemsPath'] ?? ''));

            if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
                throw new \RuntimeException('itemsPath "' . ($provider['itemsPath'] ?? '') . '" does not point to a list of products.');
            }

            $products = [];
            $skipped = 0;

            foreach ($items as $item) {
                $product = is_array($item) ? $this->mapProduct($item, $provider) : null;

                if ($product === null) {
                    $skipped++;
                    continue;
                }

                $products[$product['externalcode']] = $product; // a code listed twice keeps its last entry
            }

            if ($products === []) {
                throw new \RuntimeException('The feed had no usable products' . ($skipped ? " ($skipped skipped: missing bank, name or rate)." : '.'));
            }

            $this->store($key, $products);

            $result['status'] = 'synced';
            $result['count'] = count($products);
            $result['message'] = count($products) . ' products' . ($skipped ? ", $skipped skipped (missing bank, name or rate)" : '') . '.';
        } catch (\Throwable $exception) {
            $result['message'] = mb_substr($exception->getMessage(), 0, 250);
            log_message('error', 'Bank rate sync "{key}" failed: {message}', ['key' => $key, 'message' => $exception->getMessage()]);
        }

        $this->db()->query(
            'INSERT INTO bankprovidersync (providerkey, lastsyncat, laststatus, lastmessage, productcount) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE lastsyncat = VALUES(lastsyncat), laststatus = VALUES(laststatus), lastmessage = VALUES(lastmessage),
                                     productcount = IF(VALUES(laststatus) = \'S\', VALUES(productcount), productcount)',
            [$key, time(), $result['status'] === 'synced' ? 'S' : 'F', $result['message'], $result['count']]
        );

        return $result;
    }

    /** Raw feed -> decoded JSON. */
    protected function fetch(array $provider)
    {
        $source = $provider['source'] ?? [];
        $type = $source['type'] ?? 'http';

        if ($type === 'file') {
            $path = (string) ($source['path'] ?? '');

            if ($path === '' || ! is_file($path)) {
                throw new \RuntimeException('Feed file not found: ' . $path);
            }

            if (filesize($path) > $this->config->maxResponseBytes) {
                throw new \RuntimeException('Feed file is larger than the allowed size.');
            }

            $body = (string) file_get_contents($path);
        } elseif ($type === 'http') {
            $url = (string) ($source['url'] ?? '');

            if (! preg_match('#^https://#i', $url)) {
                throw new \RuntimeException('Provider URLs must use https.');
            }

            $headers = ['Accept' => 'application/json'];
            foreach (($source['headers'] ?? []) as $name => $value) {
                $headers[$name] = $this->resolveEnv((string) $value);
            }

            $response = Services::curlrequest(['timeout' => $this->config->timeout, 'http_errors' => false], null, null, false)
                ->get($url, ['headers' => $headers, 'timeout' => $this->config->timeout, 'connect_timeout' => $this->config->timeout]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('The provider answered HTTP ' . $response->getStatusCode() . '.');
            }

            $body = (string) $response->getBody();

            if (strlen($body) > $this->config->maxResponseBytes) {
                throw new \RuntimeException('The provider response is larger than the allowed size.');
            }
        } else {
            throw new \RuntimeException('Unknown source type "' . $type . '" (use http or file).');
        }

        $json = json_decode($body, true);

        if (! is_array($json)) {
            throw new \RuntimeException('The feed is not valid JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /** {env:NAME} placeholders -> value from .env / the environment. */
    protected function resolveEnv(string $value): string
    {
        return preg_replace_callback('/\{env:([A-Za-z0-9_.]+)\}/', static function ($m) {
            return (string) (env($m[1]) ?? '');
        }, $value);
    }

    /** Value at a dot path ("data.products", "interest.rate"); '' returns the whole value. */
    public function dig($data, string $path)
    {
        if ($path === '') {
            return $data;
        }

        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } elseif (is_array($data) && ctype_digit($segment) && array_key_exists((int) $segment, $data)) {
                $data = $data[(int) $segment];
            } else {
                return null;
            }
        }

        return $data;
    }

    /** One feed item -> catalog row, or null when bank, product name or rate is missing/invalid. */
    public function mapProduct(array $item, array $provider): ?array
    {
        $fields = $provider['fields'] ?? [];
        $get = function (string $field) use ($item, $fields) {
            return isset($fields[$field]) ? $this->dig($item, (string) $fields[$field]) : null;
        };

        $bank = $this->cleanText($provider['bank'] ?? $get('bank'), 100);
        $name = $this->cleanText($get('product'), 100);
        $rateRaw = $get('rate');

        if ($bank === '' || $name === '' || ! is_numeric($rateRaw)) {
            return null;
        }

        $rate = (float) $rateRaw;

        if (($provider['rateFormat'] ?? 'percent') === 'fraction') {
            $rate *= 100;
        }

        if ($rate < 0 || $rate > 100) {
            return null;
        }

        $typeRaw = strtolower(trim((string) $get('type')));
        $typeMap = array_change_key_case($provider['typeMap'] ?? [], CASE_LOWER);
        $type = strtoupper((string) ($typeMap[$typeRaw] ?? $typeRaw));
        $type = isset(self::TYPES[$type]) ? $type : 'OTHER';

        $code = $this->cleanText($get('code'), 100);
        if ($code === '') {
            $code = 'h:' . md5(mb_strtolower($bank . '|' . $name . '|' . $type));
        }

        $minBalance = $get('minBalance');
        $currency = strtoupper($this->cleanText($get('currency'), 3));
        $effective = $get('effectiveDate');
        $effectiveTs = is_string($effective) && $effective !== '' ? strtotime($effective) : false;

        return [
            'fees'          => $this->mapFees($item, $provider),
            'externalcode'  => $code,
            'bankname'      => $bank,
            'productname'   => $name,
            'producttype'   => $type,
            'interestrate'  => round($rate, 4),
            'minbalance'    => is_numeric($minBalance) && $minBalance >= 0 ? round((float) $minBalance, 2) : null,
            'currency'      => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'LKR',
            'effectivedate' => $effectiveTs ? date('Y-m-d', $effectiveTs) : null,
        ];
    }

    /**
     * The product's charges from the feed (provider 'fees' mapping), keyed
     * "TXNTYPE|CHANNEL". Charges with an unknown type/channel or a negative
     * amount are skipped. Empty when the provider has no fee mapping.
     */
    public function mapFees(array $item, array $provider): array
    {
        $config = $provider['fees'] ?? null;

        if (! is_array($config)) {
            return [];
        }

        $list = $this->dig($item, (string) ($config['path'] ?? ''));

        if (! is_array($list) || ! array_is_list($list)) {
            return [];
        }

        $fields = $config['fields'] ?? [];
        $typeMap = array_change_key_case($config['typeMap'] ?? [], CASE_LOWER);
        $channelMap = array_change_key_case($config['channelMap'] ?? [], CASE_LOWER);
        $number = static function ($value) {
            return (is_numeric($value) && $value >= 0) ? (float) $value : null;
        };
        $fees = [];

        foreach ($list as $charge) {
            if (! is_array($charge)) {
                continue;
            }

            $get = function (string $field) use ($charge, $fields) {
                return isset($fields[$field]) ? $this->dig($charge, (string) $fields[$field]) : null;
            };

            $typeRaw = strtolower(trim((string) $get('type')));
            $type = strtoupper((string) ($typeMap[$typeRaw] ?? $typeRaw));
            $channelRaw = strtolower(trim((string) $get('channel')));
            $channel = strtoupper((string) ($channelMap[$channelRaw] ?? $channelRaw));

            if (! isset(self::TXN_CHANNELS[$type]) || ! isset(self::CHANNELS[$channel])) {
                continue;
            }

            $flat = $get('amount');
            $percent = $get('percent');

            if (($flat !== null && $number($flat) === null) || ($percent !== null && ($number($percent) === null || $percent > 100))) {
                continue;
            }

            $min = $number($get('min'));
            $max = $number($get('max'));

            $fees[$type . '|' . $channel] = [
                'txntype'      => $type,
                'channel'      => $channel,
                'flatfee'      => round((float) ($flat ?? 0), 2),
                'percentfee'   => round((float) ($percent ?? 0), 4),
                'minfee'       => $min !== null ? round($min, 2) : null,
                'maxfee'       => ($max !== null && ($min === null || $max >= $min)) ? round($max, 2) : null,
                'freepermonth' => max(0, (int) ($number($get('freePerMonth')) ?? 0)),
                'note'         => $this->cleanText($get('note'), 150) ?: null,
            ];
        }

        return $fees;
    }

    protected function cleanText($value, int $max): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value))), 0, $max);
    }

    /** Upsert the provider's products; products no longer in the feed become inactive. */
    protected function store(string $key, array $products): void
    {
        $db = $this->db();
        $now = time();

        $db->transStart();

        $existing = $db->table('bankproduct')->select('externalcode, interestrate')->where('providerkey', $key)->get()->getResultArray();
        $existingRates = array_column($existing, 'interestrate', 'externalcode');

        foreach ($products as $code => $product) {
            unset($product['fees']);
            $row = $product + ['status' => 'A', 'lastseenat' => $now, 'updateddatetime' => $now];

            if (array_key_exists($code, $existingRates)) {
                if (round((float) $existingRates[$code], 4) !== $product['interestrate']) {
                    $row['previousrate'] = $existingRates[$code];
                }

                $db->table('bankproduct')->where(['providerkey' => $key, 'externalcode' => $code])->update($row);
            } else {
                $db->table('bankproduct')->insert($row + ['providerkey' => $key, 'createddatetime' => $now]);
            }
        }

        $db->table('bankproduct')
            ->where('providerkey', $key)
            ->whereNotIn('externalcode', array_map('strval', array_keys($products)))
            ->update(['status' => 'I', 'updateddatetime' => $now]);

        // Charges: the feed is the source of truth, so each product's list is replaced
        $ids = $db->table('bankproduct')->select('sessionid, externalcode')->where('providerkey', $key)->get()->getResultArray();
        $idByCode = array_column($ids, 'sessionid', 'externalcode');

        foreach ($products as $code => $product) {
            $productID = $idByCode[$code] ?? null;

            if ($productID === null) {
                continue;
            }

            $db->table('bankproductfee')->where('productsessionid', $productID)->delete();

            foreach ($product['fees'] ?? [] as $fee) {
                $db->table('bankproductfee')->insert($fee + ['productsessionid' => $productID, 'updateddatetime' => $now]);
            }
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            throw new \RuntimeException('Saving the products failed.');
        }
    }

    protected function ago(int $timestamp): string
    {
        $seconds = max(0, time() - $timestamp);

        return $seconds < 60 ? 'just now' : floor($seconds / 60) . ' min ago';
    }

    // ------------------------------------------------------------------
    // Catalog queries
    // ------------------------------------------------------------------

    /** Offered products, optionally of one type, best rate first within each bank. */
    public function products(string $type = ''): array
    {
        $builder = $this->db()->table('bankproduct')->where('status', 'A');

        if ($type !== '' && isset(self::TYPES[$type])) {
            $builder->where('producttype', $type);
        }

        return $builder->orderBy('bankname', 'ASC')->orderBy('producttype', 'ASC')->orderBy('interestrate', 'DESC')->get()->getResultArray();
    }

    public function product(string $sessionID): ?array
    {
        $row = $this->db()->table('bankproduct')->where('sessionid', $sessionID)->get()->getRowArray();

        return empty($row) ? null : $row;
    }

    /** Charges of one or more products: productsessionid => list of fee rows. */
    public function fees(array $productIDs): array
    {
        $productIDs = array_values(array_filter($productIDs));

        if ($productIDs === []) {
            return [];
        }

        $rows = $this->db()->table('bankproductfee')->whereIn('productsessionid', $productIDs)
            ->orderBy('txntype', 'ASC')->orderBy('channel', 'ASC')->get()->getResultArray();
        $byProduct = [];

        foreach ($rows as $row) {
            $byProduct[$row['productsessionid']][] = $row;
        }

        return $byProduct;
    }

    /** "Own bank ATM 5.00, Other bank ATM (LankaPay) 30.00 ..." for one product's charges of a type. */
    public static function feeSummary(array $fees, string $txnType = 'WITHDRAWAL'): string
    {
        $parts = [];
        $order = array_flip(self::TXN_CHANNELS[$txnType] ?? []);
        usort($fees, static fn ($a, $b) => ($order[$a['channel']] ?? 99) <=> ($order[$b['channel']] ?? 99));

        foreach ($fees as $fee) {
            if ($fee['txntype'] === $txnType) {
                $parts[] = (self::CHANNELS[$fee['channel']] ?? $fee['channel']) . ' ' . self::describeFee($fee, false);
            }
        }

        return implode(', ', $parts);
    }

    /** Human readable rule: "30.00", "free", "0.1% (min 100.00, max 1,000.00), first 2 a month free". */
    public static function describeFee(array $fee, bool $withChannel = true): string
    {
        $flat = (float) $fee['flatfee'];
        $percent = (float) $fee['percentfee'];
        $parts = [];

        if ($flat > 0) {
            $parts[] = number_format($flat, 2);
        }

        if ($percent > 0) {
            $limits = array_filter([
                $fee['minfee'] !== null ? 'min ' . number_format((float) $fee['minfee'], 2) : null,
                $fee['maxfee'] !== null ? 'max ' . number_format((float) $fee['maxfee'], 2) : null,
            ]);
            $parts[] = rtrim(rtrim(number_format($percent, 4), '0'), '.') . '%' . ($limits ? ' (' . implode(', ', $limits) . ')' : '');
        }

        $text = $parts ? implode(' + ', $parts) : 'free';

        if ((int) $fee['freepermonth'] > 0 && $parts) {
            $text .= ', first ' . (int) $fee['freepermonth'] . ' a month free';
        }

        return ($withChannel ? (self::CHANNELS[$fee['channel']] ?? $fee['channel']) . ': ' : '') . $text;
    }

    /**
     * Charge for one transaction. $usedThisMonth = transactions on the same
     * channel already made this month (for "first N free" rules).
     * Returns ['fee' => float, 'rule' => text, 'known' => bool].
     */
    public static function calculateFee(?array $fee, float $amount, int $usedThisMonth = 0): array
    {
        if ($fee === null) {
            return ['fee' => 0.0, 'rule' => 'No charge listed by the bank for this channel', 'known' => false];
        }

        $rule = self::describeFee($fee);
        $free = (int) $fee['freepermonth'];

        if ($free > 0 && $usedThisMonth < $free) {
            return ['fee' => 0.0, 'rule' => $rule . ' (' . ($free - $usedThisMonth) . ' free left this month)', 'known' => true];
        }

        $charge = (float) $fee['flatfee'];

        if ((float) $fee['percentfee'] > 0) {
            $percentPart = $amount * (float) $fee['percentfee'] / 100;

            if ($fee['minfee'] !== null) {
                $percentPart = max($percentPart, (float) $fee['minfee']);
            }

            if ($fee['maxfee'] !== null) {
                $percentPart = min($percentPart, (float) $fee['maxfee']);
            }

            $charge += $percentPart;
        }

        return ['fee' => round($charge, 2), 'rule' => $rule, 'known' => true];
    }

    /** Highest-rate offered product of the same type and currency, if it beats $rate. */
    public function betterThan(array $product): ?array
    {
        $row = $this->db()->table('bankproduct')
            ->where(['status' => 'A', 'producttype' => $product['producttype'], 'currency' => $product['currency']])
            ->where('interestrate >', $product['interestrate'])
            ->where('sessionid <>', $product['sessionid'])
            ->orderBy('interestrate', 'DESC')
            ->limit(1)->get()->getRowArray();

        return empty($row) ? null : $row;
    }

    /** Each configured provider with its last sync result. */
    public function providerStatus(): array
    {
        $sync = $this->db()->table('bankprovidersync')->get()->getResultArray();
        $syncByKey = array_column($sync, null, 'providerkey');
        $list = [];

        foreach ($this->config->providers as $key => $provider) {
            $row = $syncByKey[$key] ?? [];
            $list[] = [
                'key'      => $key,
                'name'     => $provider['name'] ?? $key,
                'sample'   => ! empty($provider['sample']),
                'source'   => ($provider['source']['type'] ?? 'http') === 'file' ? 'Bundled file' : parse_url((string) ($provider['source']['url'] ?? ''), PHP_URL_HOST),
                'lastSync' => isset($row['lastsyncat']) ? (int) $row['lastsyncat'] : null,
                'status'   => $row['laststatus'] ?? null,
                'message'  => $row['lastmessage'] ?? null,
                'count'    => (int) ($row['productcount'] ?? 0),
            ];
        }

        return $list;
    }
}
