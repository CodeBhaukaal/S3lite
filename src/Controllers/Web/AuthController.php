<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Services\Auth;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\UserService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth.login', [
            'allowRegistration' => (bool) SettingService::get('allow_registration', false),
            'twoFactorPending'  => Session::get('2fa_email'),
        ]);
    }

    public function login(Request $request): Response
    {
        $this->validate($request, [
            'email'    => 'required|email|max:190',
            'password' => 'required|string|max:200',
        ]);

        $email = strtolower($request->string('email'));
        $otp = $request->string('otp');

        $result = Auth::attempt($email, (string) $request->input('password', ''), $otp === '' ? null : $otp);

        if ($result['reason'] === 'two_factor_required') {
            Session::put('2fa_email', $email);
            Session::flashInput(['email' => $email]);

            return $this->back($request, 'info', 'Enter the 6-digit code from your authenticator app.');
        }

        if (!$result['ok']) {
            Session::flashInput(['email' => $email]);

            $message = match ($result['reason']) {
                'too_many_attempts'   => 'Too many failed attempts. Please wait a few minutes and try again.',
                'account_locked'      => 'This account is temporarily locked. Please try again later.',
                'account_suspended'   => 'This account has been suspended.',
                'account_pending'     => 'This account is awaiting verification.',
                'invalid_two_factor'  => 'That authentication code is not correct.',
                default               => 'Those credentials do not match our records.',
            };

            return $this->back($request, 'error', $message);
        }

        // Tells the throttle middleware not to count this attempt.
        $request->setAttribute('auth.succeeded', true);

        Session::forget('2fa_email');
        Auth::login((array) $result['user'], $request->bool('remember'));

        AuditService::log('auth.login', 'user', (int) $result['user']['id'], 'Signed in to the web panel');

        $intended = Session::get('intended_url');
        Session::forget('intended_url');

        return $this->redirect(
            is_string($intended) && $intended !== '' ? $intended : '/dashboard',
            'success',
            'Welcome back, ' . ($result['user']['name'] ?? '') . '.'
        );
    }

    public function showRegister(Request $request): Response
    {
        if (!SettingService::get('allow_registration', false)) {
            return $this->redirect('/login', 'error', 'Self-registration is disabled on this server.');
        }

        return $this->view('auth.register');
    }

    public function register(Request $request): Response
    {
        if (!SettingService::get('allow_registration', false)) {
            return $this->redirect('/login', 'error', 'Self-registration is disabled on this server.');
        }

        $this->validate($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190|unique:users,email',
            'password' => 'required|password|confirmed',
        ]);

        $user = UserService::create([
            'name'     => $request->string('name'),
            'email'    => $request->string('email'),
            'password' => (string) $request->input('password', ''),
            'role'     => 'user',
            'status'   => SettingService::get('require_email_verify', false) ? 'pending' : 'active',
        ]);

        AuditService::log('auth.register', 'user', (int) $user['id'], 'Registered a new account');

        if (($user['status'] ?? '') === 'pending') {
            return $this->redirect('/login', 'info', 'Your account was created and is awaiting approval.');
        }

        Auth::login($user);

        return $this->redirect('/dashboard', 'success', 'Your account is ready.');
    }

    public function logout(Request $request): Response
    {
        $userId = Auth::id();

        if ($userId !== null) {
            AuditService::log('auth.logout', 'user', $userId, 'Signed out');
        }

        Auth::logout();

        return $this->redirect('/login', 'success', 'You have been signed out.');
    }
}
