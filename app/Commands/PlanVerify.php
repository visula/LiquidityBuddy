<?php

namespace App\Commands;

use App\Libraries\PlanRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark plan:verify --user=<email> --year=2026 [--seed=<json>] [--keep-past-end]
 * Exits non-zero when any check fails.
 */
class PlanVerify extends BaseCommand
{
    use PlanCommandOptions;

    protected $group = 'LiquidityBuddy';
    protected $name = 'plan:verify';
    protected $description = 'Reconcile a user\'s monthly plan with the seed and check plan/payable consistency.';
    protected $usage = 'plan:verify --user=<email> --year=2026 [options]';
    protected $options = [
        '--user'          => 'Email or username (required)',
        '--year'          => 'Plan year (required)',
        '--seed'          => 'Seed with the expected monthly nets (default DBBackup/Seeds/planned_expenses_<year>.json)',
        '--keep-past-end' => 'Compare with the sheet\'s Total row (import was run with --keep-past-end)',
    ];

    public function run(array $params)
    {
        $login = $this->option('user');
        $year = (int) $this->option('year');

        if (! is_string($login) || $login === '' || $year < 2000 || $year > 2100) {
            CLI::error('Usage: php spark ' . $this->usage);
            return EXIT_USER_INPUT;
        }

        $seedFile = $this->option('seed') ?: ROOTPATH . 'DBBackup/Seeds/planned_expenses_' . $year . '.json';
        if (! is_file($seedFile) && is_file(ROOTPATH . ltrim($seedFile, '/\\'))) {
            $seedFile = ROOTPATH . ltrim($seedFile, '/\\'); // spark runs from public/
        }

        $seed = is_file($seedFile) ? json_decode((string) file_get_contents($seedFile), true) : null;

        if (! is_array($seed)) {
            CLI::error('Cannot read the seed ' . $seedFile);
            return EXIT_USER_INPUT;
        }

        $runner = new PlanRunner();
        $user = $runner->findUser($login);

        if ($user === null) {
            CLI::error('No active user "' . $login . '".');
            return EXIT_USER_INPUT;
        }

        $result = $runner->verify($user['sessionid'], $year, $seed, $this->option('keep-past-end') !== null);

        CLI::write(str_pad('Month', 9) . str_pad('Income', 14, ' ', STR_PAD_LEFT) . str_pad('Outflow', 14, ' ', STR_PAD_LEFT) . str_pad('Net', 14, ' ', STR_PAD_LEFT));
        foreach ($result['months'] as $ym => $t) {
            CLI::write(str_pad($ym, 9) . str_pad(number_format($t['income'], 2), 14, ' ', STR_PAD_LEFT)
                . str_pad(number_format($t['outflow'], 2), 14, ' ', STR_PAD_LEFT) . str_pad(number_format($t['net'], 2), 14, ' ', STR_PAD_LEFT));
        }
        CLI::newLine();

        foreach ($result['checks'] as [$name, $ok, $detail]) {
            CLI::write(CLI::color($ok ? 'PASS' : 'FAIL', $ok ? 'green' : 'red') . '  ' . $name . ' - ' . $detail);
        }

        CLI::newLine();
        CLI::write($result['ok'] ? CLI::color('All checks passed', 'green') : CLI::color('Some checks failed', 'red'));

        return $result['ok'] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
