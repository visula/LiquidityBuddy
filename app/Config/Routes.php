<?php

use CodeIgniter\Router\RouteCollection;

/**
 * Router options (default controller, 404 override, auto routing ...) live in
 * app/Config/Routing.php. Auto routing stays OFF: with it on, every public
 * controller/service method was callable by URL without login (e.g.
 * GET /AccountController/transactionInitProccess/... posted money into an
 * account). Every page and endpoint of the app is defined explicitly below.
 *
 * @var RouteCollection $routes
 */
/*
 * --------------------------------------------------------------------
 * Route Definitions
 * --------------------------------------------------------------------
 */

// We get a performance increase by specifying the default
// route since we don't have to scan directories.
// The site root must be '/' (CodeIgniter matches the root URI as '/', never '')
$routes->get('/', 'Home::index');
$routes->get('/login', 'Home::index');
$routes->post('/user/login-validation', 'Home::loginValidation');
$routes->get('/user/signup-page', 'Home::signup');
$routes->post('/user/signup-validation', 'Home::signupValidation');
$routes->get('/user/password-reset', 'Home::passwordRest');
// $routes->get('', 'Home::index');

// Google login
// $routes->get('google-login', 'GoogleLoginController::login');
// $routes->get('google-callback', 'GoogleLoginController::callback');
$routes->get('/user/logout', 'GoogleLoginController::logout');

// Account informations
$routes->get('/account/list', 'AccountController::accountList');
$routes->get('/account/add', 'AccountController::addAccount');
$routes->get('/budget/add', 'AccountController::addBudget');
$routes->get('/account/info/(:any)', 'AccountController::accountPage/$1');

$routes->get('/account/group', 'AccountController::accountGroupPage');

// JS requests: Account
$routes->post('/js-request/account/create', 'AccountController::createAccount');
$routes->post('/js-request/account/update', 'AccountController::updateAccount');
$routes->post('/js-request/account/delete', 'AccountController::deleteAccount');

// JS requests: Budget
$routes->post('/js-request/budget/create', 'AccountController::createBudget');
$routes->post('/js-request/budget/update', 'AccountController::updateBudget');
$routes->post('/js-request/budget/delete', 'AccountController::deleteBudget');

// JS requests: Account group
$routes->post('/js-request/account-group/create', 'AccountController::createAccountGroup');
$routes->post('/js-request/account-group/update', 'AccountController::updateAccountGroup');
$routes->post('/js-request/account-group/delete', 'AccountController::deleteAccountGroup');

// Transaction information
$routes->get('/transaction/list', 'AccountController::transactionList');
$routes->get('/transaction/add', 'TransactionController::createTransaction');

// JS requests: Transaction
$routes->post('/js-request/account/transferred', 'AccountController::internalTransaction');
$routes->post('/js-request/payment/expense', 'PurchaseController::expenseTransaction');
$routes->post('/js-request/payment/income', 'PurchaseController::incomeTransaction');
$routes->post('/js-request/payment/dued', 'PurchaseController::duedTransaction');
$routes->post('/js-request/payment/purchase', 'PurchaseController::purchaseTransaction');
$routes->post('/js-request/payment/settle', 'PurchaseController::duedTransaction');

// Account wise payment methods + payment ledger
$routes->get('/payment-method/list', 'PaymentController::paymentMethodPage');
$routes->get('/payment/history', 'PaymentController::paymentHistoryPage');
$routes->post('/js-request/payment-method/create', 'PaymentController::createPaymentMethod');
$routes->post('/js-request/payment-method/update', 'PaymentController::updatePaymentMethod');
$routes->post('/js-request/payment-method/delete', 'PaymentController::deletePaymentMethod');

// Recurring (continuous) transactions
$routes->get('/recurring/list', 'PaymentController::recurringPage');
$routes->post('/js-request/recurring/create', 'PaymentController::createRecurring');
$routes->post('/js-request/recurring/update', 'PaymentController::updateRecurring');

// Bank rates (providers in app/Config/BankRates.php), account products, interest
$routes->get('/bank-rates', 'BankController::ratesPage');
$routes->post('/js-request/bank-rates/sync', 'BankController::syncRates');
$routes->post('/js-request/account/bank-product', 'BankController::linkProduct');
$routes->post('/js-request/account/transfer/quote', 'AccountController::internalTransferQuote');
$routes->post('/js-request/account/interest/quote', 'BankController::interestQuoteRequest');
$routes->post('/js-request/account/interest/post', 'BankController::interestPostRequest');

// Monthly payment plan (docs/MONTHLY_PLAN.md)
$routes->get('/plan', 'PlanController::index');
$routes->get('/plan/items', 'PlanController::itemsPage');
$routes->get('/plan/(:num)', 'PlanController::yearPage/$1');
$routes->get('/plan/(:num)/(:num)', 'PlanController::monthPage/$1/$2');
$routes->post('/js-request/plan/item/create', 'PlanController::itemCreate');
$routes->post('/js-request/plan/item/update', 'PlanController::itemUpdate');
$routes->post('/js-request/plan/item/end', 'PlanController::itemEnd');
$routes->post('/js-request/plan/item/delete', 'PlanController::itemDelete');
$routes->post('/js-request/plan/entry/update', 'PlanController::entryUpdate');
$routes->post('/js-request/plan/entry/skip', 'PlanController::entrySkip');
$routes->post('/js-request/plan/entry/restore', 'PlanController::entryRestore');
$routes->post('/js-request/plan/salary-advance', 'PlanController::salaryAdvance');
$routes->post('/js-request/plan/category/create', 'PlanController::categoryCreate');
$routes->post('/js-request/plan/category/update', 'PlanController::categoryUpdate');

// Reports
$routes->get('/report/transactions/(:any)', 'ReportController::transactionReport/$1');

// JS requests: Report
$routes->post('/js-request/transaction/income-expense', 'ReportController::reportIncomeExpense');
$routes->post('/js-request/transaction/purchase', 'ReportController::reportPurchaseReport');
