<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Boot;
use App\Support\Branding;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            ActivityLog::record('Failed login for '.$data['email'].' from '.$request->ip(), 'auth', 'WARNING');
            throw ValidationException::withMessages(['email' => "That email and password don't match."]);
        }

        $user = $request->user();
        if ($user->status === 'Suspended') {
            Auth::logout();

            return response()->json(['message' => 'Account suspended.', 'suspended' => true], 403);
        }

        $request->session()->regenerate();
        ActivityLog::record(($user->isAdmin() ? 'Admin' : 'User').' login: '.$user->email.' from '.$request->ip(), 'auth');

        return ['user' => Boot::me($user), 'csrf' => csrf_token()];
    }

    public function register(Request $request)
    {
        $toggles = (array) Setting::get('toggles', []);
        if (($toggles['reg'] ?? true) === false) {
            abort(403, 'Registration is closed on this installation.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $free = Plan::where('price', 0)->orderBy('sort')->first();
        $limits = (array) Setting::get('limits', []);
        $signupCredits = (int) ($limits['limit_0'] ?? $free?->credits ?? 50);

        $user = User::create($data + ['plan_id' => $free?->id, 'credits' => 0, 'status' => 'Active']);
        $user->adjustCredits($signupCredits, 'Sign-up credits');
        static::verificationRequired() ? $this->sendCode($user) : $user->forceFill(['email_verified_at' => now()])->save();
        Auth::login($user);
        $request->session()->regenerate();
        ActivityLog::record($user->name.' signed up on the '.($free?->name ?? 'Free').' plan', 'auth');

        return ['user' => Boot::me($user->fresh()), 'csrf' => csrf_token(), 'verify' => static::verificationRequired()];
    }

    public static function verificationRequired(): bool
    {
        return (bool) (((array) Setting::get('toggles', []))['verify'] ?? true);
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);
        $user = $request->user();
        if ($user->email_verified_at) {
            return ['ok' => true];
        }
        [$hash, $expires] = array_pad(explode('|', (string) $user->verify_code), 2, 0);
        if (! $hash || (int) $expires < time() || ! hash_equals($hash, hash('sha256', $user->id.'|'.$request->input('code')))) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired. Request a new one.']);
        }
        $user->forceFill(['email_verified_at' => now(), 'verify_code' => null])->save();
        ActivityLog::record($user->email.' verified their email', 'auth');

        return ['ok' => true, 'user' => Boot::me($user->fresh())];
    }

    public function resend(Request $request)
    {
        if (! $request->user()->email_verified_at) {
            $this->sendCode($request->user());
        }

        return ['ok' => true];
    }

    private function sendCode(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $user->forceFill(['verify_code' => hash('sha256', $user->id.'|'.$code).'|'.(time() + 3600)])->save();
        $app = Branding::appName();
        try {
            Mail::raw("Your {$app} verification code is {$code}.\n\nIt expires in one hour. If you didn't create an account, ignore this email.", function ($m) use ($user, $app) {
                $m->to($user->email, $user->name)->subject("{$code} is your {$app} verification code");
            });
        } catch (\Throwable $e) {
            ActivityLog::record('Could not send verification email to '.$user->email.': '.mb_substr($e->getMessage(), 0, 200), 'mail', 'ERROR');
        }
    }

    public function logout(Request $request)
    {
        if ($id = $request->session()->pull('impersonator_id')) {
            Auth::loginUsingId($id);
            $request->session()->regenerate();

            return ['ok' => true, 'restored' => true];
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['ok' => true];
    }

    public function forgot(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        // Same answer whether or not the account exists.
        return ['ok' => true];
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required', 'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return ['ok' => true];
    }
}
