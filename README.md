# cao/laravel-cao-sso

CAO SSO client integration for Laravel applications. Provides an OAuth2
authorization-code login flow against a **CAO** server using Laravel Socialite,
with a configurable `cao` driver, published migration, routes, and a user
matching strategy.

It is designed so another Laravel + Nuxt application can install SSO with
minimal wiring.

## Requirements

- PHP `^8.2`
- Laravel `^11` or `^12`
- `laravel/socialite` `^5.31` (installed automatically)
- The app's SPA is served from a host listed in `SANCTUM_STATEFUL_DOMAINS`
  (the callback uses the `web` session, and the SPA authenticates via session).

## Installation

Because this package lives inside the repo under `packages/`, you can require it
via a path repository:

```jsonc
// composer.json (of your Laravel app)
{
  "repositories": [
    { "type": "path", "url": "packages/laravel-cao-sso", "options": { "symlink": true } }
  ],
  "require": {
    "cao/laravel-cao-sso": "*"
  }
}
```

```bash
composer update cao/laravel-cao-sso
```

The service provider is auto-discovered. To publish the config and migration:

```bash
php artisan vendor:publish --tag=cao-sso
php artisan migrate
```

## Configuration

Add these to your `.env`:

```dotenv
# Credentials issued by CAO when this app was registered as an SSO system.
CAO_SSO_CLIENT_ID=
CAO_SSO_CLIENT_SECRET=

# OAuth endpoints of the CAO server.
CAO_SSO_REDIRECT_URI="${APP_URL}/api/auth/callback"
CAO_SSO_AUTHORIZE_URL="http://127.0.0.1:8002/oauth/authorize"
CAO_SSO_TOKEN_URL="http://127.0.0.1:8002/oauth/token"
CAO_SSO_USERINFO_URL="http://127.0.0.1:8002/api/oauth/userinfo"

# Where to send the browser after login (your SPA).
CAO_SSO_FRONTEND_URL="http://127.0.0.1:8080"

# Optional.
CAO_SSO_USER_MODEL="App\Models\User"
CAO_SSO_PROFILE_MODEL=            # e.g. App\Models\Profile
CAO_SSO_TEAMS_ENABLED=false
```

`config/cao-sso.php` documents every option, including the column names used for
matching, team support, and whether CAO-initiated logins are accepted.

### Registering the redirect URI at CAO

Register exactly `CAO_SSO_REDIRECT_URI` as a redirect URI on the CAO SSO system.
The default is `{APP_URL}/api/auth/callback`.

## Routes

Registered automatically (configurable under `cao-sso.routes`):

| Method | Path                     | Name              |
|--------|--------------------------|-------------------|
| GET    | `/api/auth/sso/cao`      | `cao-sso.login`   |
| GET    | `/api/auth/callback`     | `cao-sso.callback`|

## How it works

1. The user is sent to the CAO authorization page (either from your SPA's
   login button, or from CAO's own Apps launcher).
2. CAO redirects back to the callback with an authorization code.
3. Socialite exchanges the code for a token and fetches the user profile.
4. The user is matched (see below), bound to CAO, and logged in.
5. The browser is redirected to `cao-sso.frontend_url` with a session cookie.

### User matching

`Cao\LaravelCaoSso\Support\CaoUserMatcher`:

1. **Primary** — the CAO `sub` (stable id) stored on `cao_user_id`.
2. **Fallback** — `email`, then `username` (legacy users).
3. **Otherwise** — a new user is created.

`cao_client_id` is treated as rotating configuration and refreshed on every
login; it is never part of the identity. If an email match resolves to a user
whose stored `cao_user_id` differs, the login is rejected (`sso_user_mismatch`)
to avoid account takeover.

### CAO-initiated (launcher) logins

When a user clicks an app in CAO's Apps page, CAO sends the browser **directly**
to its OAuth server with its own `state`. This app therefore never stored a
`state`, and Socialite would raise a false `InvalidStateException`.

With `allow_cao_initiated_login` (default `true`), the callback detects a
well-formed response from CAO and retries statelessly. This is safe because the
state was already validated server-side by CAO, and the code is single-use and
bound to this `client_id` + `redirect_uri`. Set it to `false` to require this
app to always initiate the login.

## Frontend (Nuxt)

The `resources/nuxt/` folder contains the client-side pieces needed so the SPA
hydrates from the server session after the SSO redirect. See
[`resources/nuxt/README.md`](resources/nuxt/README.md).

Without this step, the SPA's route guard runs while the store is still empty and
bounces the freshly logged-in user back to `/login`.

## Security notes

- Keep `CAO_SSO_CLIENT_SECRET` out of version control.
- The callback runs in the `web` middleware group so it has session support.
- The session id is regenerated on login (fixation protection).
