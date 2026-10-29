<?php

namespace App\Controllers;

/**
 * Monthly Payment Plan pages (year grid, month view, items) and their JSON
 * endpoints. All logic lives in PlanService; money is only moved by the
 * existing /js-request/payment/settle endpoint.
 */
class PlanController extends PlanService
{
    /** Session user for a page, or null (the caller redirects to /login). */
    protected function pageUser()
    {
        $is_login = session()->get('is_login');

        return (! isset($is_login) || ! $is_login) ? null : $this->userAccess();
    }

    protected function planPageData(string $title, string $currentID): array
    {
        return [
            'StoredText' => ['Header' => $title, 'ScreenTitle' => $title, 'ErrorStatus' => 'Error Status: '],
            'Head'       => $this->commonHead(),
            'CurrentID'  => $currentID,
        ];
    }

    /** GET /plan */
    public function index()
    {
        if ($this->pageUser() === null) {
            return redirect()->to(base_url('/login'));
        }

        return redirect()->to(base_url('/plan/' . date('Y')));
    }

    /** GET /plan/(:num) - year grid */
    public function yearPage($year = null)
    {
        $userID = $this->pageUser();

        if ($userID === null) {
            return redirect()->to(base_url('/login'));
        }

        $year = (int) $year;

        if ($year < 2000 || $year > 2100) {
            return redirect()->to(base_url('/plan/' . date('Y')));
        }

        $this->planRollOnOpen($userID);

        $grid = $this->planYearGrid($userID, $year);
        $allocationMonth = (string) $this->request->getGet('m');
        $allocationMonth = preg_match('/^\d{4}-\d{2}$/', $allocationMonth) && substr($allocationMonth, 0, 4) === (string) $year
            ? $allocationMonth : ((string) $year === date('Y') ? date('Y-m') : $year . '-01');

        $data = $this->planPageData('Monthly Plan ' . $year, 'plan-year');
        $data['grid'] = $grid;
        $data['allocation'] = $this->planAllocation($grid, $allocationMonth);
        $data['allocationPercentTotal'] = $this->planAllocationPercentTotal($userID, 'OUT');
        $data['incomePercentTotal'] = $this->planAllocationPercentTotal($userID, 'IN');
        $data['hasItems'] = $this->db()->table('planitem')->where('usersessionid', $userID)->where('status <>', 'D')->countAllResults() > 0;

        return view('Plan/year', $data);
    }

    /** GET /plan/(:num)/(:num) - month view */
    public function monthPage($year = null, $month = null)
    {
        $userID = $this->pageUser();

        if ($userID === null) {
            return redirect()->to(base_url('/login'));
        }

        $year = (int) $year;
        $month = (int) $month;

        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return redirect()->to(base_url('/plan/' . date('Y/n')));
        }

        $this->planRollOnOpen($userID);

        $data = $this->planPageData(date('F Y', mktime(0, 0, 0, $month, 1, $year)), 'plan-month');
        $data['view'] = $this->planMonthView($userID, $year, $month);
        $data['accounts'] = $this->rowsOf($this->activeAccountListAccessModule($userID));
        $data['budgetInfo'] = $this->rowsOf($this->budgetList($userID));
        $data['paymentMethodInfo'] = $this->paymentMethodList($userID);

        return view('Plan/month', $data);
    }

    /** GET /plan/items */
    public function itemsPage()
    {
        $userID = $this->pageUser();

        if ($userID === null) {
            return redirect()->to(base_url('/login'));
        }

        $data = $this->planPageData('Plan Items', 'plan-items');
        $data['items'] = $this->planItemList($userID);
        $data['categories'] = $this->planCategories($userID);
        $data['allocationPercentTotal'] = $this->planAllocationPercentTotal($userID, 'OUT');
        $data['incomePercentTotal'] = $this->planAllocationPercentTotal($userID, 'IN');
        $data['accounts'] = $this->rowsOf($this->activeAccountListAccessModule($userID));
        $data['budgetInfo'] = $this->rowsOf($this->budgetList($userID));
        $data['paymentMethodInfo'] = $this->paymentMethodList($userID);
        $data['amountTypes'] = $this->planAmountTypes;

        return view('Plan/items', $data);
    }

    // ------------------------------------------------------------------
    // JSON endpoints
    // ------------------------------------------------------------------

    /** Only the item form fields, so unknown POST keys never reach the service. */
    protected function planItemFields(array $request): array
    {
        $fields = ['name', 'direction', 'kind', 'category_id', 'amount_type', 'default_amount', 'due_day', 'start_month', 'end_date',
            'account_id', 'payment_method', 'to_account_id', 'budget_id', 'source_item_id', 'share_ratio', 'stakeholder', 'notes', 'recurring'];

        return array_intersect_key($request, array_flip($fields));
    }

    /** POST /js-request/plan/item/create */
    public function itemCreate()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        return $this->apiResult($this->planItemCreate($userID, $this->planItemFields($this->handlePOSTBodyDataList())), 'Plan item added');
    }

    /** POST /js-request/plan/item/update */
    public function itemUpdate()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planItemUpdate($userID, (string) ($request['item_id'] ?? ''), $this->planItemFields($request)), 'Plan item updated');
    }

    /** POST /js-request/plan/item/end */
    public function itemEnd()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planItemEnd($userID, (string) ($request['item_id'] ?? ''), (string) ($request['end_date'] ?? '')), 'Plan item ended');
    }

    /** POST /js-request/plan/item/delete */
    public function itemDelete()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planItemDelete($userID, (string) ($request['item_id'] ?? '')), 'Plan item deleted');
    }

    /** POST /js-request/plan/entry/update  (entry_id, amount, apply_forward) */
    public function entryUpdate()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();
        $result = $this->planEntryUpdate($userID, (string) ($request['entry_id'] ?? ''), $request['amount'] ?? null,
            filter_var($request['apply_forward'] ?? false, FILTER_VALIDATE_BOOLEAN));

        return $this->apiResult($result, 'Plan updated');
    }

    /** POST /js-request/plan/entry/skip */
    public function entrySkip()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planEntrySkip($userID, (string) ($request['entry_id'] ?? '')), 'Month skipped');
    }

    /** POST /js-request/plan/entry/restore */
    public function entryRestore()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planEntryRestore($userID, (string) ($request['entry_id'] ?? '')), 'Month restored');
    }

    /** POST /js-request/plan/salary-advance (item_id, month YYYY-MM, amount) */
    public function salaryAdvance()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planSalaryAdvance($userID, (string) ($request['item_id'] ?? ''), $request['month'] ?? '', $request['amount'] ?? null), 'Salary advance recorded');
    }

    /** POST /js-request/plan/category/create */
    public function categoryCreate()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planCategoryCreate($userID, array_intersect_key($request, array_flip(['name', 'direction', 'allocation_percent']))), 'Category added');
    }

    /** POST /js-request/plan/category/update  (category_id, allocation_percent, name) */
    public function categoryUpdate()
    {
        $userID = $this->apiUser();

        if ($userID === null) {
            return $this->apiUnauthorised();
        }

        $request = $this->handlePOSTBodyDataList();

        return $this->apiResult($this->planCategoryUpdate($userID, (string) ($request['category_id'] ?? ''),
            array_intersect_key($request, array_flip(['name', 'allocation_percent']))), 'Category updated');
    }
}
