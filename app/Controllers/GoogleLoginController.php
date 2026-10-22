<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Google_Client;
use Google_Service_Oauth2;

class GoogleLoginController extends Controller
{
    private $clientId = '663934361450-b4ih79cu3cllkaor1lk980u5he4rdkcd.apps.googleusercontent.com';
    private $clientSecret = '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';
    private $redirectUri = '/google-callback';

    public function login()
    {
        // Initialize the Google Client
        $client = new Google_Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri(base_url($this->redirectUri));
        $client->addScope('email');
        $client->addScope('profile');

        // Generate Google login URL and redirect
        $login_url = $client->createAuthUrl();
        return redirect()->to($login_url);
    }

    public function callback()
    {
        $this->redirectUri = base_url($this->redirectUri);
        $client = new Google_Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri(base_url($this->redirectUri));

        // Get the authorization code
        if ($this->request->getVar('code')) {

            $token = $client->fetchAccessTokenWithAuthCode($this->request->getVar('code'));
            $client->setAccessToken($token);

            // Get user profile info
            $oauth2 = new Google_Service_Oauth2($client);
            $googleUser = $oauth2->userinfo->get();


            // Now you can use this information to log in or register the user
            $userData = [
                'GoogleID' => $googleUser->id,
                'UserFirstName' => $googleUser->givenName,
                'UserLastName' => $googleUser->familyName,
                'UserEmail' => $googleUser->email,
                'ProfilePic' => $googleUser->picture
                // 'logged_in' => true
            ];

            $fe_user = new FEUser();
            $existingUser = $fe_user->googleAccountHandle($userData);


            if(!$existingUser['is_login']){
                return redirect()->to(base_url('/login'))->with('status','Google login failure');
            }

            // Store user data in session
            session()->set($existingUser);

            // Redirect to dashboard or any page
            return redirect()->to(base_url());
        } else {
            // If no authorization code, redirect to login page
            return redirect()->to(base_url('/login'))->with('status','Google login failure');

        }
    }

    public function logout()
    {
        // Destroy the session
        session()->destroy();

        // Redirect to login
        return redirect()->to(base_url('login'));
    }

    // public function googleUserId
}
