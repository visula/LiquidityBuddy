<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Bank rate providers: where bank products (savings, current, fixed deposit ...),
 * their interest rates and their charges (withdrawal, deposit, transfer) come
 * from, and how each feed maps onto the catalog.
 *
 * Add a provider by adding an entry to $providers - no code changes needed:
 *
 *   'mybank' => [
 *       'name'      => 'My Bank open data',
 *       'source'    => ['type' => 'http', 'url' => 'https://api.example.com/rates',
 *                       'headers' => ['Authorization' => 'Bearer {env:BANKRATES_MYBANK_TOKEN}']],
 *       'itemsPath' => 'data.products',          // dot path to the list of products ('' = the root is the list)
 *       'fields'    => [                         // catalog field => dot path inside one product
 *           'code'          => 'id',             // stable id; optional (bank + product name is used when missing)
 *           'bank'          => 'bank.name',      // required
 *           'product'       => 'name',           // required
 *           'type'          => 'category',       // mapped through typeMap
 *           'rate'          => 'interest.rate',  // required, annual
 *           'minBalance'    => 'minimumBalance',
 *           'currency'      => 'currency',
 *           'effectiveDate' => 'interest.from',  // Y-m-d (or anything strtotime() reads)
 *       ],
 *       'typeMap'    => ['savings' => 'SAVINGS', 'current' => 'CURRENT', 'fd' => 'FIXED_DEPOSIT'],
 *       'rateFormat' => 'percent',               // 'percent' (4.25) or 'fraction' (0.0425)
 *       'bank'       => null,                    // fixed bank name when the feed has no bank field
 *
 *       // Optional: bank charges per product (withdrawal / deposit / transfer).
 *       'fees' => [
 *           'path'   => 'charges',                // dot path inside one product to its list of charges
 *           'fields' => [                         // fee field => dot path inside one charge
 *               'type'         => 'transaction',  // withdrawal | deposit | transfer (through typeMap)
 *               'channel'      => 'channel',      // through channelMap -> OWN_ATM, OTHER_ATM, COUNTER, CDM, ONLINE_OWN, ONLINE_OTHER
 *               'amount'       => 'flat',         // fixed charge
 *               'percent'      => 'percent',      // % of the amount (0.1 = 0.1%)
 *               'min'          => 'min',          // floor / cap for percentage charges
 *               'max'          => 'max',
 *               'freePerMonth' => 'freePerMonth', // first N a month are free
 *               'note'         => 'note',
 *           ],
 *           'typeMap'    => ['withdrawal' => 'WITHDRAWAL', 'deposit' => 'DEPOSIT', 'transfer' => 'TRANSFER'],
 *           'channelMap' => ['atm' => 'OWN_ATM', 'lankapay' => 'OTHER_ATM', 'branch' => 'COUNTER'],
 *       ],
 *   ],
 *
 * Security: providers are defined here, never by users, so the server only
 * fetches URLs a developer configured. HTTP sources must use https, and API
 * keys come from .env through {env:NAME} placeholders, never from this file.
 */
class BankRates extends BaseConfig
{
    /** Seconds before an HTTP provider times out. */
    public int $timeout = 10;

    /** Largest response accepted from a provider, in bytes. */
    public int $maxResponseBytes = 2_000_000;

    /** A provider is fetched again from the Bank Rates page at most this often (seconds). */
    public int $minSyncInterval = 300;

    /** The catalog is refreshed automatically when it is older than this (seconds); 0 = never. */
    public int $autoSyncAfter = 86400;

    public array $providers = [
        // Bundled sample so the feature works out of the box. The rates are
        // illustrative only - replace or add a real provider for live data.
        'sample' => [
            'name'      => 'Sample feed (illustrative rates, not live)',
            'source'    => ['type' => 'file', 'path' => APPPATH . 'Data/bank_rates_sample.json'],
            'itemsPath' => 'data.products',
            'fields'    => [
                'code'          => 'id',
                'bank'          => 'bank.name',
                'product'       => 'name',
                'type'          => 'category',
                'rate'          => 'interest.annualRate',
                'minBalance'    => 'minimumBalance',
                'currency'      => 'currency',
                'effectiveDate' => 'interest.effectiveFrom',
            ],
            'typeMap'    => [
                'savings'       => 'SAVINGS',
                'current'       => 'CURRENT',
                'fixed_deposit' => 'FIXED_DEPOSIT',
            ],
            'rateFormat' => 'percent',
            'fees'       => [
                'path'   => 'charges',
                'fields' => [
                    'type'         => 'transaction',
                    'channel'      => 'channel',
                    'amount'       => 'flat',
                    'percent'      => 'percent',
                    'min'          => 'min',
                    'max'          => 'max',
                    'freePerMonth' => 'freePerMonth',
                    'note'         => 'note',
                ],
                'typeMap'    => ['withdrawal' => 'WITHDRAWAL', 'deposit' => 'DEPOSIT', 'transfer' => 'TRANSFER'],
                'channelMap' => [
                    'own_atm'      => 'OWN_ATM',
                    'other_atm'    => 'OTHER_ATM',
                    'counter'      => 'COUNTER',
                    'cdm'          => 'CDM',
                    'online_own'   => 'ONLINE_OWN',
                    'online_other' => 'ONLINE_OTHER',
                ],
            ],
            'sample'     => true,
        ],
    ];
}
