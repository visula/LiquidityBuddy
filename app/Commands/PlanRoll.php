<?php

namespace App\Commands;

use App\Libraries\PlanRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark plan:roll [--user=<email>] [--months=12]
 * Extends recurring plan items up to the current month + --months, never past
 * their end date. Safe to run any time (e.g. monthly from cron): months that
 * already exist are left alone.
 */
class PlanRoll extends BaseCommand
{
    use PlanCommandOptions;

    protected $group = 'LiquidityBuddy';
    protected $name = 'plan:roll';
    protected $description = 'Extend open-ended monthly plan items (all users, or one with --user).';
    protected $usage = 'plan:roll [--user=<email>] [--months=12]';
    protected $options = [
        '--user'   => 'Email or username (default: every user with plan items)',
        '--months' => 'Months past the current one to generate (default 12)',
    ];

    public function run(array $params)
    {
        $months = $this->option('months');
        $months = ($months === null || $months === true) ? 12 : (int) $months;

        if ($months < 1 || $months > 60) {
            CLI::error('--months must be between 1 and 60.');
            return EXIT_USER_INPUT;
        }

        $runner = new PlanRunner();
        $login = $this->option('user');

        if (is_string($login) && $login !== '') {
            $user = $runner->findUser($login);

            if ($user === null) {
                CLI::error('No active user "' . $login . '".');
                return EXIT_USER_INPUT;
            }

            $users = [$user['sessionid'] => $user['email'] ?: $user['username']];
        } else {
            $users = array_fill_keys($runner->planUsers(), null);
        }

        $total = 0;

        foreach ($users as $userID => $label) {
            $created = $runner->roll($userID, $months);
            $total += $created;
            CLI::write(str_pad((string) ($label ?? $userID), 40) . ' ' . $created . ' month(s) added');
        }

        CLI::write(CLI::color('Done: ' . $total . ' month(s) added for ' . count($users) . ' user(s).', 'green'));

        return EXIT_SUCCESS;
    }
}
