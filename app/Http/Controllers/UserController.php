<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\KeyManager;
use App\Services\ProfileSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

class UserController extends Controller
{
    public function showSignupForm()
    {
        return view('signup_form');
    }

    public function signup(Request $request, ProfileSecurity $profiles, KeyManager $keys, Google2FA $google2fa)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email',
            'phone'    => 'required|string|max:20',
            'address'  => 'required|string|max:500',
            'nid_no'   => 'required|string|max:50',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $emailHash = ProfileSecurity::emailHash($request->email);
        if (User::where('email_hash', $emailHash)->exists()) {
            return back()->withInput()->withErrors(['email' => 'The email has already been taken.']);
        }

        $keys->ensureSystemKeys();
        $encrypted = $profiles->encryptProfile([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'nid_no' => $request->nid_no,
        ]);

        $secret = $google2fa->generateSecretKey();

        $user = User::create([
            'name' => $encrypted['name'],
            'email' => $encrypted['email'],
            'email_hash' => $encrypted['email_hash'],
            'phone' => $encrypted['phone'],
            'address' => $encrypted['address'],
            'nid_no' => $encrypted['nid_no'],
            'mac' => $encrypted['mac'],
            'password' => Hash::make($request->password),
            'google2fa_secret' => $profiles->encryptTotpSecret($secret),
        ]);

        $userKeys = $keys->generateUserKeys((int) $user->id);
        $user->rsa_public_key = $userKeys['rsa_public_key'];
        $user->ecc_public_key = $userKeys['ecc_public_key'];
        $user->save();

        $qrUrl = $google2fa->getQRCodeUrl('ExchangeIT', $request->email, $secret);

        return redirect('/login')->with([
            'success' => 'Account created successfully! Scan the QR code with your authenticator app, then login.',
            'otp_secret' => $secret,
            'otp_qr' => $qrUrl,
        ]);
    }

    public function showLoginForm()
    {
        return view('login_form');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email_hash', ProfileSecurity::emailHash($request->email))->first();

        if ($user && Hash::check($request->password, $user->password)) {
            session([
                'pending_2fa_user_id' => $user->id,
            ]);

            return redirect('/login/otp');
        }

        return back()->with('error', 'Invalid email or password');
    }

    public function showOtpForm()
    {
        if (!session()->has('pending_2fa_user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        return view('auth.otp');
    }

    public function verifyOtp(Request $request, ProfileSecurity $profiles, Google2FA $google2fa)
    {
        if (!session()->has('pending_2fa_user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $request->validate([
            'one_time_password' => 'required|string',
        ]);

        $user = User::find(session('pending_2fa_user_id'));
        if (!$user || !$user->google2fa_secret) {
            session()->forget('pending_2fa_user_id');
            return redirect('/login')->with('error', 'Unable to verify OTP.');
        }

        $secret = $profiles->decryptTotpSecret($user->google2fa_secret);
        if (!$google2fa->verifyKey($secret, $request->one_time_password)) {
            return back()->with('error', 'Invalid OTP code.');
        }

        if (!str_starts_with((string) $user->google2fa_secret, 'rsa_')) {
            $user->google2fa_secret = $profiles->encryptTotpSecret($secret);
        }

        try {
            $plain = $profiles->decryptProfile($user);
        } catch (RuntimeException $e) {
            return redirect('/login')->with('error', 'Profile integrity check failed.');
        }

        $token = Str::random(64);
        $user->session_token = hash('sha256', $token);
        $user->save();

        session()->forget('pending_2fa_user_id');
        session()->regenerate();
        session([
            'user_id' => $user->id,
            'user_name' => $plain['name'],
            'user_email' => $plain['email'],
            'session_token' => $token,
            'is_admin' => $user->isAdmin(),
        ]);

        return redirect('/dashboard');
    }

    public function dashboard()
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $user = User::find(session('user_id'));
        if (!$user) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $userName = session('user_name') ?: 'User';
        $isAdmin = session('is_admin') || $user->isAdmin();

        if ($isAdmin) {
            return view('admin.dashboard', compact('userName'));
        }

        return view('dashboard', compact('userName'));
    }

    public function editProfile(ProfileSecurity $profiles)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $user = User::findOrFail(session('user_id'));

        try {
            $plain = $profiles->decryptProfile($user);
        } catch (RuntimeException $e) {
            return redirect('/dashboard')->with('error', 'Profile integrity check failed.');
        }

        return view('profile.edit', [
            'user' => (object) array_merge($plain, ['id' => $user->id]),
        ]);
    }

    public function updateProfile(Request $request, ProfileSecurity $profiles)
    {
        if (!session()->has('user_id')) {
            return redirect('/login')->with('error', 'Please login first.');
        }

        $user = User::findOrFail(session('user_id'));

        try {
            $current = $profiles->decryptProfile($user);
        } catch (RuntimeException $e) {
            return redirect('/dashboard')->with('error', 'Profile integrity check failed.');
        }

        $request->validate([
            'name'   => 'required|string|max:255',
            'phone'  => 'required|string|max:20',
            'address'=> 'required|string|max:500',
            'nid_no' => 'required|string|max:50',
            'password' => 'nullable|string|min:6|confirmed',
        ]);

        $encrypted = $profiles->encryptProfile([
            'name' => $request->name,
            'email' => $current['email'],
            'phone' => $request->phone,
            'address' => $request->address,
            'nid_no' => $request->nid_no,
        ]);

        $user->name = $encrypted['name'];
        $user->email = $encrypted['email'];
        $user->email_hash = $encrypted['email_hash'];
        $user->phone = $encrypted['phone'];
        $user->address = $encrypted['address'];
        $user->nid_no = $encrypted['nid_no'];
        $user->mac = $encrypted['mac'];

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();
        session(['user_name' => $request->name]);

        return redirect()->route('profile.edit')->with('success', 'Profile updated successfully.');
    }

    public function logout()
    {
        if (session()->has('user_id')) {
            $user = User::find(session('user_id'));
            if ($user) {
                $user->session_token = null;
                $user->save();
            }
        }

        session()->flush();
        return redirect('/login')->with('success', 'Logged out successfully!');
    }
}
