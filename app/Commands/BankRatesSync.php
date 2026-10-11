<?php

namespace App\Commands;

use App\Libraries\BankRateSync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark bankrates:sync [--provider <key>] [--force]
 *
 * Refreshes bank products and interest rates from the providers in
 * app/Config/BankRates.php. Schedule it daily (cron / Task Scheduler) to keep
 * rates current without anyone opening the Bank Rates page.
 */
class BankRatesSync extends BaseCommand
{
    protected $group = 'LiquidityBuddy';
    protected $name = 'bankrates:sync';
    protected $description = 'Pull bank products and interest rates from the configured providers.';
    protected $usage = 'bankrates:sync [options]';
    protected $options = [
        '--provider' => 'Only sync this provider key',
        '--force'    => 'Sync even if the provider was synced a few minutes ago',
    ];

    public function run(array $params)
    {
        $only = CLI::getOption('provider');
        $results = (new BankRateSync())->syncAll(is_string($only) && $only !== '' ? $only : null, CLI::getOption('force') !== null);
        $failed = 0;

        foreach ($results as $result) {
            $color = ['synced' => 'green', 'skipped' => 'yellow', 'failed' => 'red'][$result['status']] ?? 'white';
            CLI::write(str_pad($result['provider'], 16) . CLI::color(str_pad($result['status'], 8), $color) . ' ' . $result['message']);
            $failed += $result['status'] === 'failed' ? 1 : 0;
        }

        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
