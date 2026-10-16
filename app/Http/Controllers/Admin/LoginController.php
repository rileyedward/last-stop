<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One admin login from ADMIN_USERNAME and ADMIN_PASSWORD. No users table:
 * a good login sets a session flag.
 */
class LoginController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get(EnsureAdmin::SESSION_KEY) === true) {
            return redirect()->route('admin.rules');
        }

        return Inertia::render('admin/Login', [
            'configured' => $this->username() !== '' && $this->password() !== '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $key = 'admin-login:'.Str::lower($validated['username']).'|'.$request->ip();
        $limit = (int) config('last-stop.admin.attempts_per_minute');
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors(['username' => "Too many tries. Wait {$seconds} seconds."])->onlyInput('username');
        }

        if (! $this->matches($validated['username'], $validated['password'])) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['username' => "That username and password don't match."])->onlyInput('username');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(EnsureAdmin::SESSION_KEY, true);

        return redirect()->intended(route('admin.rules'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureAdmin::SESSION_KEY);
        $request->session()->regenerate();

        return redirect()->route('admin.login');
    }

    private function matches(string $username, string $password): bool
    {
        $expectedUser = $this->username();
        $expected = $this->password();
        if ($expectedUser === '' || $expected === '') {
            return false;
        }

        $userOk = hash_equals($expectedUser, $username);
        // A bcrypt or argon hash is checked as a hash, anything else as text.
        $passOk = password_get_info($expected)['algo'] !== null
            ? password_verify($password, $expected)
            : hash_equals($expected, $password);

        return $userOk && $passOk;
    }

    private function username(): string
    {
        $value = config('last-stop.admin.username');

        return is_string($value) ? $value : '';
    }

    private function password(): string
    {
        $value = config('last-stop.admin.password');

        return is_string($value) ? $value : '';
    }
}
