<?php

namespace Cao\LaravelCaoSso\Http\Controllers;

use Cao\LaravelCaoSso\Support\CaoUserMatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class SsoController extends Controller
{
    /**
     * Redirect the user to the CAO OAuth authorization page.
     */
    public function redirectToCao()
    {
        // Socialite generates and stores the OAuth "state" in the session
        // (session-based CSRF protection). Validation happens in the callback.
        return Socialite::driver('cao')->redirect();
    }

    /**
     * Handle the callback from the CAO OAuth server.
     */
    public function handleCaoCallback(Request $request)
    {
        $frontendUrl = rtrim(config('cao-sso.frontend_url'), '/');

        try {
            $caoUser = $this->resolveCaoUser(Socialite::driver('cao'), $request);
            Log::channel(config('cao-sso.log_channel'))->info('CAO SSO user received', [
                'user' => $caoUser->getRaw(),
            ]);
        } catch (InvalidStateException $e) {
            Log::channel(config('cao-sso.log_channel'))->warning('CAO SSO state mismatch or expired', [
                'request_state' => $request->query('state'),
            ]);

            return redirect($frontendUrl.'/login?error=sso_state_mismatch');
        } catch (\Throwable $e) {
            Log::channel(config('cao-sso.log_channel'))->error('CAO SSO failed', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'class' => get_class($e),
            ]);

            return redirect($frontendUrl.'/login?error=sso_failed');
        }

        $user = (new CaoUserMatcher)->resolve($caoUser);

        if (! $user) {
            return redirect($frontendUrl.'/login?error=sso_user_mismatch');
        }

        if ($user->{config('cao-sso.columns.disabled_at')}) {
            Auth::logout();

            return redirect($frontendUrl.'/login?error=account_disabled');
        }

        // Log the user in and rotate the session id (fixation protection).
        Auth::login($user, true);
        $request->session()->regenerate();

        if (config('cao-sso.teams.enabled')) {
            $teamKey = config('cao-sso.teams.foreign_key', 'team_id');
            session(['team_id' => $user->{$teamKey}]);
            setPermissionsTeamId(session('team_id'));
        }

        return redirect($frontendUrl);
    }

    /**
     * Resolve the CAO user for the callback, tolerating the CAO launcher flow.
     *
     * When this app initiated the login we have a session-stored "state" and
     * Socialite validates it normally. When CAO's Apps page launched the flow we
     * have no local state, so we retry statelessly to avoid a false
     * InvalidStateException. A request without an authorization code is never
     * treated as valid.
     */
    protected function resolveCaoUser($driver, Request $request)
    {
        try {
            return $driver->user();
        } catch (InvalidStateException $e) {
            if (! config('cao-sso.allow_cao_initiated_login', true)) {
                throw $e;
            }

            if (! $request->filled('code') || ! $request->filled('state')) {
                throw $e;
            }

            Log::channel(config('cao-sso.log_channel'))->info(
                'CAO SSO callback without local state (launcher flow)',
                ['request_state' => $request->query('state')]
            );

            return Socialite::driver('cao')->stateless()->user();
        }
    }
}
