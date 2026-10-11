<?php

namespace App\Controllers;
use DateTime;

class Home extends MainService
{
    public function index()
    {

        $is_login = session()->get('is_login');

        if(isset($is_login) && $is_login){
            return redirect()->to(base_url('/account/list'));
        }

        $data = [
            'StoredText'=>[
                "ScreenTitle" => "Login",
            "ErrorStatus" => "Error Login",
            "Header" => "Login to your Account",
            "EmailLabel" => "Email:",
            "EmailPlaceholder" => "Enter Email",
            "PasswordLabel" => "Password:",
            "PasswordPlaceholder" => "Enter Password",
            "GoogleIcon" => "assets/media/img/logo/iconfinder-new-google-favicon-682665.png",
            "LoginButton" => "Login",
            "SignupLink" => "/user/signup-page",
            "SignupPara" => "Not a member yet? ",
            "SignupAnchor" => "Sign up now",
            "PassworResetLink" => "/user/password-reset",
            "PassworResetPara" => "Forgot password? ",
            "PassworResetAnchor" => "Reset password"
            ]
        ];

        return view('User/login', $data);
    }

    /**
     * Active user whose email or username matches $login (case-insensitive), or null.
     * Usernames can't contain '@' and are unique, so one login can only ever match one user.
     */
    protected function findUserByLogin(string $login)
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        $row = \Config\Database::connect()->table('user')
            ->select('sessionid AS UserSessionID, password AS UserPassword, email AS UserEmail, username AS Username,
                      google_id AS GoogleID, first_name AS UserFirstName, last_name AS UserLastName, profile_pic AS ProfilePic', false)
            ->groupStart()->where('email', $login)->orWhere('username', $login)->groupEnd()
            ->where('status', 'A')
            ->get()->getRowArray();

        return empty($row) ? null : $row;
    }

    /** Sets the logged in session (and the login history row) for $user. */
    protected function startUserSession(array $user)
    {
        $jwtEncription = $this->generateJWT(['UserSessionID' => $user['UserSessionID']]);

        $insertHistory = $this->insertDatatoDB('user_history', [
                'UserSessionID' => $user['UserSessionID'],
                'UserHistoryStartedDateTime' => time(),
                'UserHistoryJWTToken' => $jwtEncription
            ]);

        if(isset($insertHistory['error_id'])){
            log_message('error', 'Login history not saved: {message}', ['message' => $insertHistory['error_message'] ?? '']);
        }

        session()->regenerate();
        session()->set([
            'user_fname'  => $user['UserFirstName'],
            'user_lname'  => $user['UserLastName'],
            'profile_pic' => $user['ProfilePic'],
            'user_id'     => $user['UserSessionID'],
            'jwt_encrypt' => $jwtEncription,
            'is_login'    => true
        ]);
    }

    /** POST /user/login-validation  (field "email" takes an email or a username) */
    public function loginValidation()
    {
        $request_data = $this->handlePOSTBodyDataList();
        $user = $this->findUserByLogin((string) ($request_data['email'] ?? ''));

        if($user !== null && !empty($user['GoogleID'])){
            return redirect()->to(base_url('/login'))->withInput()->with('status','This is a google link account, please use the google account to login in.');
        }

        // Same message for an unknown login and a wrong password, so logins can't be probed
        if($user === null || $this->encryptLogic((string) ($request_data['password'] ?? '')) !== $user['UserPassword']){
            return redirect()->to(base_url('/login'))->withInput()->with('status', 'Incorrect email/username or password.');
        }

        $this->startUserSession($user);

        return redirect()->to(base_url());
    }

    public function signup()
    {
        $is_login = session()->get('is_login');

        if(isset($is_login) && $is_login){
            return redirect('/account/list')->to(base_url());
        }

        $StoredText = [
            "ScreenTitle" => "Signup",
            "ErrorStatus" => "Signup Error",
            "Header" => "Login to your Account",
            "FirstNameLabel" => "First Name:",
            "FirstNamePlaceholder" => "First Name",
            "LastNameLabel" => "Last Name:",
            "LastNamePlaceholder" => "Last Name",
            "EmailLabel" => "Email:",
            "EmailPlaceholder" => "Enter Email",
            "PasswordLabel" => "Password:",
            "PasswordPlaceholder" => "Enter Password",
            "RePasswordLabel" => "Re-Password:",
            "RePasswordPlaceholder" => "Enter Re-Password",
            "GoogleIcon" => "assets/media/img/logo/iconfinder-new-google-favicon-682665.png",
            "SignupButton" => "Create a New Account",
            "LoginLink" => "/login",
            "LoginPara" => "Already have an account? ",
            "LoginAnchor" => "Login"
        ];

        $data = [
            'StoredText' => $StoredText
        ];

        return view('User/signup',$data);
    }

    /**
     * POST /user/signup-validation
     * Needs first and last name, a password (8+ chars, repeated) and a username,
     * an email or both. Creates the user with a Default account group and budget,
     * then logs them in.
     */
    public function signupValidation()
    {
        $request_data = $this->handlePOSTBodyDataList();
        $back = function (string $message) {
            return redirect()->to(base_url('/user/signup-page'))->withInput()->with('status', $message);
        };

        $fname = trim((string) ($request_data['fname'] ?? ''));
        $lname = trim((string) ($request_data['lname'] ?? ''));
        $username = trim((string) ($request_data['username'] ?? ''));
        $email = trim((string) ($request_data['email'] ?? ''));
        $password = (string) ($request_data['password'] ?? '');

        if($fname === '' || $lname === '' || mb_strlen($fname) > 50 || mb_strlen($lname) > 50){
            return $back('Please enter your first and last name.');
        }

        if($username === '' && $email === ''){
            return $back('Please enter a username, an email or both.');
        }

        if($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)){
            return $back('A username needs 3-50 letters, digits, dots, dashes or underscores.');
        }

        if($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 50)){
            return $back('Please enter a valid email address.');
        }

        if(isset($this->passwordFormatHandler($password)['error_id'])){
            return $back('The password needs at least 8 characters.');
        }

        if($password !== (string) ($request_data['repassword'] ?? '')){
            return $back('The two passwords do not match.');
        }

        foreach (array_filter([$username, $email]) as $login) {
            $existing = $this->findUserByLogin($login);

            if($existing !== null){
                return redirect()->to(base_url('login'))->withInput()->with('status', !empty($existing['GoogleID'])
                    ? 'This is a google link account, please use the google account to login in.'
                    : 'That username or email already has an account. Please log in.');
            }
        }

        $userData = [
            'UserFirstName' => $fname,
            'UserLastName'  => $lname,
            'UserPassword'  => $this->encryptLogic($password),
            'UserCreatedAt' => time(),
            'UserUpdatedAt' => time(),
        ];

        if($username !== ''){
            $userData['Username'] = $username;
        }

        if($email !== ''){
            $userData['UserEmail'] = $email;
        }

        $user = $this->runAtomic(function () use ($userData, $username, $email) {
            $insertUser = $this->insertDatatoDB('user', $userData);

            if(isset($insertUser['error_id'])){
                return $insertUser;
            }

            $user = $this->findUserByLogin($username !== '' ? $username : $email);

            if($user === null){
                return ['error_id' => '0005', 'error_message' => 'The new user could not be read back.'];
            }

            // Every user starts with a Default account group and the Default (unplanned) budget
            $defaults = [
                ['accountgroup', ['UserSessionID' => $user['UserSessionID'], 'AccountGroupName' => 'Default']],
                ['budget', ['UserSessionID' => $user['UserSessionID'], 'BudgetName' => 'Default', 'BudgetAmount' => 0, 'BudgetPeriodic' => 'M']],
            ];

            foreach ($defaults as [$table, $row]) {
                $response = $this->insertDatatoDB($table, $row);

                if(isset($response['error_id'])){
                    return $response;
                }
            }

            return $user;
        });

        if(isset($user['error_id'])){
            log_message('error', 'Signup failed: {message}', ['message' => $user['error_message'] ?? '']);
            return $back('The account could not be created, please try again.');
        }

        $this->startUserSession($user);

        return redirect()->to(base_url());
    }

    public function passwordRest()
    {
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url());
        }

        $StoredText = [
            "ScreenTitle" => "Login",
            // "ErrorStatus" => "Test",
            "Header" => "Login to your Account",
            "OldPasswordLabel" => "Enter Password:",
            "OldPasswordPlaceholder" => "Enter Password",
            "NewPasswordLabel" => "Enter New Password:",
            "NewPasswordPlaceholder" => "Enter New  Password",
            "RePasswordLabel" => "Re-enter Password:",
            "RePasswordPlaceholder" => "Re-enter Password",
            "SignupButton" => "Create a New Account",
            "LoginLink" => "/login",
            "LoginPara" => "Already have an account? ",
            "LoginAnchor" => "Login"
        ];

        $data = [
            'StoredText' => $StoredText
        ];

        return view('FEUser/passswordReset',$data);
    

    }

    // Google account login and Sign in method
    public function googleAccountHandle($userData)
    {
        $dateTime  = strtotime(date_format(new DateTime(),'Y-m-d H:i:s'));

        $user = $this->getDatafromDB(
                ['user'],
                ['UserEmail' => $userData['UserEmail']],
                ['UserSessionID', 'UserEmail', 'GoogleID', 'UserFirstName', 'UserLastName', 'ProfilePic']
            );

        if(isset($user['error_id'])){
            
            // Error ID 0004 means no user found, this will create that google user to table
            if($user['error_id'] == "0004"){
                $insertUser = $this->insertDatatoDB('user', $userData);

                if(isset($insertUser['error_id'])){
                    // TODO:: DB access fail log need
                    // TODO:: Send to common Error
                }

                $user = $this->getDatafromDB(
                        ['user'],
                        ['UserEmail' => $userData['UserEmail']],
                        ['UserSessionID', 'UserEmail', 'GoogleID', 'UserFirstName', 'UserLastName', 'ProfilePic']
                    );

                if(isset($user['error_id'])){
                    // TODO:: DB access fail log need
                    // TODO:: Send to common Error
                }

            }else{
                // TODO:: DB access fail log need
                // TODO:: Send to common Error
            }
        }

        // if any details have changed in gmail like => FirstName, Last Name or Profile Picture this condtion will update the following data
        if($user['GoogleID'] == '' || $userData['UserFirstName'] != $user['UserFirstName'] || $userData['UserLastName'] != $user['UserLastName'] || $userData['ProfilePic'] != $user['ProfilePic']){

            if($user['GoogleID'] == ''){
                $updateList['GoogleID'] = $userData['GoogleID'];
            }

            if($userData['UserFirstName'] != $user['UserFirstName']){
                $updateList['UserFirstName'] = $userData['UserFirstName'];
            }

            if($userData['UserLastName'] != $user['UserLastName']){
                $updateList['UserLastName'] = $userData['UserLastName'];
            }
            
            if($userData['ProfilePic'] != $user['ProfilePic']){
                $updateList['ProfilePic'] = $userData['ProfilePic'];
            }

            $this->updateDBData('user', ['UserEmail' => $userData['UserEmail']], $updateList);

            $user = $this->getDatafromDB(
                    ['user'],
                    ['UserEmail' => $userData['UserEmail']],
                    ['UserSessionID', 'UserEmail', 'GoogleID', 'UserFirstName', 'UserLastName', 'ProfilePic']
                );

            if(isset($user['error_id'])){
                // TODO:: DB access fail log need
                // TODO:: Send to common Error
                return ['is_login' => false];
            }
        }

        // Google ID is not matching
        if($userData['GoogleID'] != $user['GoogleID']){
            // TODO:: Error Log
            // TODO:: Login Fail -> to redirect to login page
            return ['is_login' => false];
        }

        $jwtData = [
            'UserSessionID' => $user['UserSessionID']
            ];
        $jwtEncription = $this->generateJWT($jwtData);
                
        $insertUser = $this->insertDatatoDB('user_history', ['UserSessionID' => $user['UserSessionID'], 'UserHistoryStartedDateTime' => $dateTime, 'UserHistoryJWTToken' => $jwtEncription]);

        if(isset($insertUser['error_id'])){
            // TODO:: DB access fail log need
            // TODO:: Send to common Error
        }

        $data = [
            'user_fname' => $userData['UserFirstName'],
            'user_lname' => $userData['UserLastName'],
            'profile_pic' => $userData['ProfilePic'],
            'user_id' => $user['UserSessionID'],
            'jwt_encrypt' => $jwtEncription,
            'is_login' => true
            ];

        return $data;

    }
}
