<?php

namespace App\Controllers;

/**
 * Forgot / reset password.
 *
 *   GET  /user/forgot-password      form: email or username
 *   POST /user/forgot-password      always answers the same way, so it can't show who has an account
 *   GET  /user/reset/{token}        form: new password (only when the token is valid)
 *   POST /user/reset                sets the password, then the token can't be used again
 *
 * The emailed token is random; only its SHA-256 hash is stored. Tokens expire after 30 minutes
 * and can be used once. Setting a new password ends every other open token for that user.
 */
class PasswordResetController extends MainService
{
    protected int $tokenMinutes = 30;

    public function forgotForm()
    {
        return view('User/forgot_password', ['StoredText' => $this->authText()]);
    }

    public function forgotSubmit()
    {
        $request_data = $this->handlePOSTBodyDataList();
        $login = trim((string) ($request_data['login'] ?? ''));

        if ($login === '') {
            return redirect()->to(base_url('/user/forgot-password'))->withInput()->with('status', 'Enter your email or username.');
        }

        $user = $this->resetUserByLogin($login);

        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            $now = time();

            $db = \Config\Database::connect();
            $db->table('passwordreset')->insert([
                'usersessionid'   => $user['UserSessionID'],
                'tokenhash'       => hash('sha256', $token),
                'expiresdatetime' => $now + $this->tokenMinutes * 60,
                'createddatetime' => $now,
            ]);

            $link = base_url('user/reset/' . $token);

            if (! $this->sendResetEmail($user['UserEmail'], $user['Username'] ?? '', $link)) {
                // Mail isn't configured or failed. In development the link goes to the log so the flow can still be tested.
                if (ENVIRONMENT === 'development') {
                    log_message('warning', 'Password reset link (development only, mail not sent): {link}', ['link' => $link]);
                }
                log_message('error', 'Password reset email could not be sent for user {id}', ['id' => $user['UserSessionID']]);
            }
        }

        return redirect()->to(base_url('/user/forgot-password'))
            ->with('success', 'If an account matches that email or username, a reset link has been sent. The link expires in ' . $this->tokenMinutes . ' minutes.');
    }

    public function resetForm($token = '')
    {
        if ($this->findValidToken((string) $token) === null) {
            return view('User/reset_password', ['StoredText' => $this->authText(), 'token' => '', 'invalid' => true]);
        }

        return view('User/reset_password', ['StoredText' => $this->authText(), 'token' => (string) $token, 'invalid' => false]);
    }

    public function resetSubmit()
    {
        $request_data = $this->handlePOSTBodyDataList();
        $token = (string) ($request_data['token'] ?? '');
        $password = (string) ($request_data['password'] ?? '');
        $repeat = (string) ($request_data['repassword'] ?? '');
        $back = fn (string $message) => redirect()->to(base_url('/user/reset/' . $token))->with('status', $message);

        if ($this->findValidToken($token) === null) {
            return redirect()->to(base_url('/user/forgot-password'))->with('status', 'That reset link is invalid or has expired. Request a new one.');
        }

        if (strlen($password) < 8) {
            return $back('The password needs at least 8 characters.');
        }

        if ($password !== $repeat) {
            return $back('The two passwords do not match.');
        }

        $row = $this->findValidToken($token);
        $db = \Config\Database::connect();
        $now = time();

        $db->transStart();
        $db->table('user')->set(['password' => $this->encryptLogic($password)])
            ->where('sessionid', $row['usersessionid'])->update();
        $db->table('passwordreset')->set(['useddatetime' => $now])
            ->where('usersessionid', $row['usersessionid'])->where('useddatetime', null)->update();
        $db->transComplete();

        if (! $db->transStatus()) {
            return $back('The password could not be changed. Please try again.');
        }

        return redirect()->to(base_url('/login'))->with('status', 'Your password has been changed. Log in with the new password.');
    }

    /** The unused, unexpired token row for this token, or null. */
    protected function findValidToken(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = \Config\Database::connect()->table('passwordreset')
            ->where('tokenhash', hash('sha256', $token))
            ->where('useddatetime', null)
            ->where('expiresdatetime >', time())
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** Active user by email or username, same rule as login. */
    protected function resetUserByLogin(string $login): ?array
    {
        $row = \Config\Database::connect()->table('user')
            ->select('sessionid AS UserSessionID, email AS UserEmail, username AS Username')
            ->groupStart()->where('email', $login)->orWhere('username', $login)->groupEnd()
            ->where('status', 'A')
            ->get()->getRowArray();

        return ($row && ! empty($row['UserEmail'])) ? $row : null;
    }

    protected function sendResetEmail(string $to, string $name, string $link): bool
    {
        $email = \Config\Services::email();
        $email->setTo($to);
        $email->setSubject('Reset your Liquidity Buddy password');
        $email->setMessage(
            '<p>Hello' . ($name !== '' ? ' ' . esc($name) : '') . ',</p>'
            . '<p>Use this link to choose a new password. It expires in ' . $this->tokenMinutes . ' minutes and works once.</p>'
            . '<p><a href="' . esc($link, 'attr') . '">Reset my password</a></p>'
            . '<p>If you did not ask for this, you can ignore this email.</p>'
        );

        try {
            return (bool) $email->send(false);
        } catch (\Throwable $exception) {
            log_message('error', 'Reset email failed: {message}', ['message' => $exception->getMessage()]);

            return false;
        }
    }

    protected function authText(): array
    {
        return [
            'ScreenTitle'   => 'Reset password',
            'LoginLink'     => '/login',
        ];
    }
}
