<?php

namespace App\Commands;

use App\Libraries\PlanRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark plan:import <json> --user=<email> [--from=YYYY-MM] [--map=<json>] [--update]
 *        [--keep-past-end] [--create-missing-accounts [--card-limit=<amount>]] [--dry-run]
 */
class PlanImport extends BaseCommand
{
    use PlanCommandOptions;

    protected $group = 'LiquidityBuddy';
    protected $name = 'plan:import';
    protected $description = 'Import a monthly plan seed (liquiditybuddy.monthly-plan/v1) for a user.';
    protected $usage = 'plan:import <json> --user=<email> [options]';
    protected $arguments = ['json' => 'Seed file, e.g. DBBackup/Seeds/planned_expenses_2026.json'];
    protected $options = [
        '--user'                    => 'Email or username of the user (required)',
        '--from'                    => 'Tracking start YYYY-MM: payables from this month on, earlier months are history (default: current month)',
        '--map'                     => 'JSON file mapping hint names to your real account / method / budget names',
        '--update'                  => 'Update items, and amounts of unsettled entries, from the seed',
        '--keep-past-end'           => 'Keep months planned after an item\'s end date (end moves to the 28th of the last month)',
        '--create-missing-accounts' => 'Create missing credit card accounts (zero balance)',
        '--card-limit'              => 'Credit limit for created card accounts (default 0)',
        '--dry-run'                 => 'Do everything, report, then roll back',
    ];

    public function run(array $params)
    {
        $file = $params[0] ?? null;
        $login = $this->option('user');

        if ($file === null || ! is_string($login) || $login === '') {
            CLI::error('Usage: php spark ' . $this->usage);
            return EXIT_USER_INPUT;
        }

        $seed = $this->readJson($file);
        $map = $this->option('map');
        $map = is_string($map) && $map !== '' ? $this->readJson($map) : [];

        if ($seed === null || $map === null) {
            return EXIT_USER_INPUT;
        }

        $runner = new PlanRunner();
        $user = $runner->findUser($login);

        if ($user === null) {
            CLI::error('No active user "' . $login . '".');
            return EXIT_USER_INPUT;
        }

        $dryRun = $this->option('dry-run') !== null;
        $report = $runner->import($user['sessionid'], $seed, [
            'from'                    => $this->option('from'),
            'map'                     => $map,
            'update'                  => $this->option('update') !== null,
            'keep_past_end'           => $this->option('keep-past-end') !== null,
            'create_missing_accounts' => $this->option('create-missing-accounts') !== null,
            'card_limit'              => $this->option('card-limit') ?? 0,
            'dry_run'                 => $dryRun,
        ]);

        if (isset($report['error_id'])) {
            CLI::error($report['error_message']);
            return EXIT_ERROR;
        }

        CLI::write(($dryRun ? CLI::color('DRY RUN - nothing saved', 'yellow') : CLI::color('Imported', 'green'))
            . ' for ' . ($user['email'] ?: $user['username']) . ', tracking from ' . substr($report['from'], 0, 7));

        foreach (['categories', 'items', 'entries'] as $section) {
            CLI::write(str_pad(ucfirst($section), 12) . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($report[$section]), $report[$section])));
        }
        CLI::write(str_pad('Payables', 12) . 'created ' . $report['payables_created']);

        $lists = [
            'accounts_created' => 'Accounts created',
            'budgets_created'  => 'Budgets created',
            'end_moved'        => 'End dates moved (--keep-past-end)',
            'trimmed'          => 'Months not created (after the item\'s end date)',
            'unresolved'       => 'Unresolved',
            'zero_limit_cards' => 'Credit cards with a 0 limit (their dues fail until a limit is set)',
            'warnings'         => 'Seed warnings',
        ];

        foreach ($lists as $key => $title) {
            if (! empty($report[$key])) {
                CLI::newLine();
                CLI::write($title . ' (' . count($report[$key]) . '):', $key === 'unresolved' ? 'red' : 'yellow');
                foreach ($report[$key] as $line) {
                    CLI::write('  - ' . $line);
                }
            }
        }

        return EXIT_SUCCESS;
    }

    protected function readJson(string $file): ?array
    {
        // spark runs from public/, so a relative path is taken from the project root
        if (! is_file($file) && is_file(ROOTPATH . ltrim($file, '/\\'))) {
            $file = ROOTPATH . ltrim($file, '/\\');
        }

        if (! is_file($file)) {
            CLI::error('File not found: ' . $file);
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            CLI::error($file . ' is not valid JSON: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }
}
