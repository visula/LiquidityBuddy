<?php

namespace Config;

// Create a new instance of our RouteCollection class.
$routes = Services::routes();

// Load the system's routing file first, so that the app and ENVIRONMENT
// can override as needed.
if (file_exists(SYSTEMPATH . 'Config/Routes.php')) {
    require SYSTEMPATH . 'Config/Routes.php';
}

/*
 * --------------------------------------------------------------------
 * Router Setup
 * --------------------------------------------------------------------
 */
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Home');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
// Auto routing is OFF: with it on, every public controller/service method was
// callable by URL without login (e.g. GET /AccountController/transactionInitProccess/...
// posted money into an account). All app URLs are defined explicitly below.
$routes->setAutoRoute(false);

/*
 * --------------------------------------------------------------------
 * Route Definitions
 * --------------------------------------------------------------------
 */

// We get a performance increase by specifying the default
// route since we don't have to scan directories.
$routes->get('', 'Home::index');
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
$routes->get('/account/change', 'AccountController::changeAccount');
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

// Reports
$routes->get('/report/transactions/(:any)', 'ReportController::transactionReport/$1');

// JS requests: Report
$routes->post('/js-request/transaction/income-expense', 'ReportController::reportIncomeExpense');
$routes->post('/js-request/transaction/purchase', 'ReportController::reportPurchaseReport');



/*
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 *
 * There will often be times that you need additional routing and you
 * need it to be able to override any defaults in this file. Environment
 * based routes is one such time. require() additional route files here
 * to make that happen.
 *
 * You will have access to the $routes object within that file without
 * needing to reload it.
 */
if (file_exists(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
