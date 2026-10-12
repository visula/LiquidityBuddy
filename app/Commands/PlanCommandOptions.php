<?php

namespace App\Commands;

use CodeIgniter\CLI\CLI;

/**
 * Plan commands accept both "--user value" (CodeIgniter's own syntax) and
 * "--user=value": CodeIgniter keeps the latter as an option named "user=value".
 */
trait PlanCommandOptions
{
    /** Option value, true for a flag given without a value, null when absent. */
    protected function option(string $name)
    {
        $value = CLI::getOption($name);

        if ($value !== null) {
            return $value;
        }

        foreach (CLI::getOptions() as $key => $given) {
            if (strpos((string) $key, $name . '=') === 0) {
                return substr((string) $key, strlen($name) + 1);
            }
        }

        return null;
    }
}
