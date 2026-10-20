<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CAO OAuth credentials
    |--------------------------------------------------------------------------
    |
    | These values are issued by CAO when an SSO system (app) is registered.
    | They identify this application to the CAO OAuth server.
    |
    */

    'client_id' => env('CAO_SSO_CLIENT_ID'),

    'client_secret' => env('CAO_SSO_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | OAuth endpoints
    |--------------------------------------------------------------------------
    |
    | The authorize/token/userinfo endpoints of the CAO API server. Override
    | these per environment (the values below match a local CAO API on :8002).
    |
    */

    'redirect' => env('CAO_SSO_REDIRECT_URI', env('APP_URL').'/api/auth/callback'),

    'authorize_url' => env('CAO_SSO_AUTHORIZE_URL', 'http://127.0.0.1:8002/oauth/authorize'),

    'token_url' => env('CAO_SSO_TOKEN_URL', 'http://127.0.0.1:8002/oauth/token'),

    'userinfo_url' => env('CAO_SSO_USERINFO_URL', 'http://127.0.0.1:8002/api/oauth/userinfo'),

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | Space/comma separated scopes requested from CAO.
    |
    */

    'scopes' => [],

    /*
    |--------------------------------------------------------------------------
    | Route registration
    |--------------------------------------------------------------------------
    |
    | Where the package registers its login + callback routes. The OAuth
    | callback needs session support, so the "web" middleware group is used.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'api/auth',
        'middleware' => ['web'],
        'login_path' => 'sso/cao',      // GET {prefix}/sso/cao
        'callback_path' => 'callback',  // GET {prefix}/callback
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend redirect
    |--------------------------------------------------------------------------
    |
    | Where the user is sent after a successful (or failed) SSO login. This is
    | the SPA that consumes this API.
    |
    */

    'frontend_url' => env('CAO_SSO_FRONTEND_URL', env('MITD_FRONTEND_URL', env('APP_URL', 'http://127.0.0.1:8080'))),

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model that represents an authenticatable user in your app.
    |
    */

    'user_model' => env('CAO_SSO_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Users table
    |--------------------------------------------------------------------------
    |
    | The migration published by this package adds the CAO binding columns to
    | this table. Defaults to the table configured for the "users" auth provider.
    |
    */

    'users_table' => env('CAO_SSO_USERS_TABLE', 'users'),

    /*
    |--------------------------------------------------------------------------
    | Profile model (optional)
    |--------------------------------------------------------------------------
    |
    | If your app stores the user's display name on a related profile model,
    | set it here. The matcher will update the "full_name" column when present.
    | Leave null to skip profile handling entirely.
    |
    */

    'profile_model' => env('CAO_SSO_PROFILE_MODEL', null),

    'profile' => [
        'user_foreign_key' => 'user_id',
        'name_column' => 'full_name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Column names
    |--------------------------------------------------------------------------
    |
    | Columns on your users table used to bind the CAO identity. The migration
    | published by this package creates "cao_user_id" and "cao_client_id".
    |
    */

    'columns' => [
        'cao_user_id' => 'cao_user_id',
        'cao_client_id' => 'cao_client_id',
        'username' => 'username',
        'email' => 'email',
        'disabled_at' => 'disabled_at',
        'email_verified_at' => 'email_verified_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Teams (spatie/laravel-permission)
    |--------------------------------------------------------------------------
    |
    | When your app uses teams, the matched user's team is written to the
    | session after login. Set to false to disable.
    |
    */

    'teams' => [
        'enabled' => (bool) env('CAO_SSO_TEAMS_ENABLED', false),
        'foreign_key' => 'team_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted launcher
    |--------------------------------------------------------------------------
    |
    | When true, the callback accepts a well-formed OAuth response that was
    | initiated by CAO (the CAO Apps launcher) even though no "state" was
    | stored in this app's session. Passport validates the state server-side
    | against the authenticated CAO session, and the code is single-use and
    | bound to this client_id + redirect_uri, so this is safe for the launcher
    | flow. Set to false to require this app to always initiate the login.
    |
    */

    'allow_cao_initiated_login' => (bool) env('CAO_SSO_ALLOW_CAO_INITIATED', true),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'log_channel' => env('CAO_SSO_LOG_CHANNEL', null),
];
