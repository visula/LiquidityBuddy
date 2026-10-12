<?php

namespace App\Libraries;

/**
 * Public entry points to PlanService for spark commands.
 * PlanService keeps its methods protected (they must never be reachable by
 * URL); this class lives outside App\Controllers and has no routes.
 */
class PlanRunner extends \App\Controllers\PlanService
{
    /** Active user by email or username, or null. */
    public function findUser(string $login): ?array
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        $row = $this->db()->table('user')->select('sessionid, email, username')
            ->groupStart()->where('email', $login)->orWhere('username', $login)->groupEnd()
            ->where('status', 'A')->get()->getRowArray();

        return empty($row) ? null : $row;
    }

    /** Users that have plan items (plan:roll without --user). */
    public function planUsers(): array
    {
        return array_column($this->db()->table('planitem')->distinct()->select('usersessionid')->where('status', 'A')->get()->getResultArray(), 'usersessionid');
    }

    public function import(string $userID, array $seed, array $options)
    {
        return $this->importPlan($userID, $seed, $options);
    }

    public function verify(string $userID, int $year, array $seed, bool $keepPastEnd): array
    {
        return $this->verifyPlan($userID, $year, $seed, $keepPastEnd);
    }

    public function roll(string $userID, int $months): int
    {
        return $this->runAtomic(function () use ($userID, $months) {
            return ['success' => true, 'created' => $this->rollPlanForward($userID, $months)];
        })['created'] ?? 0;
    }

    public function totals(string $userID, int $year): array
    {
        return $this->planTotalsByMonth($userID, $year);
    }
}
