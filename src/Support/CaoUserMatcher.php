<?php

namespace Cao\LaravelCaoSso\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Resolves (and keeps in sync) the local application user that corresponds to a
 * CAO SSO identity.
 *
 * Matching strategy:
 *   1. Primary: the CAO "sub" (stable user id) bound on cao_user_id.
 *   2. Fallback: email, then username — for users created before SSO existed.
 *   3. Otherwise: a new user is provisioned.
 *
 * The cao_client_id is treated as rotating configuration and refreshed on every
 * login; it is never used as part of the identity.
 */
class CaoUserMatcher
{
    public function __construct(
        protected ?string $caoClientId = null,
    ) {
        $this->caoClientId ??= config('cao-sso.client_id');
    }

    /**
     * Resolve the local user for the given CAO identity, creating or refreshing
     * it as required. Returns null only when a conflicting binding is detected
     * (see matchByEmail()).
     */
    public function resolve(SocialiteUser $caoUser): ?object
    {
        $columns = config('cao-sso.columns');
        $model = $this->userModel();

        $caoUserId = (string) $caoUser->getId();
        $caoUsername = $caoUser->getNickname();
        $caoEmail = $caoUser->getEmail();
        $raw = $caoUser->getRaw();

        // 1. Primary match — stable CAO identity.
        $user = $model->newQuery()
            ->where($columns['cao_user_id'], $caoUserId)
            ->first();

        // 2. Fallback — email (legacy users without a CAO binding).
        if (! $user && $caoEmail) {
            $user = $model->newQuery()->where($columns['email'], $caoEmail)->first();

            if ($user && $user->{$columns['cao_user_id']} && $user->{$columns['cao_user_id']} !== $caoUserId) {
                Log::channel(config('cao-sso.log_channel'))->warning(
                    'CAO SSO user id mismatch for existing user',
                    [
                        'local_user_id' => $user->getKey(),
                        'local_username' => $user->{$columns['username']} ?? null,
                        'stored_cao_user_id' => $user->{$columns['cao_user_id']},
                        'current_cao_user_id' => $caoUserId,
                        'cao_email' => $caoEmail,
                    ]
                );

                return null;
            }
        }

        // 3. Fallback — username (CAO users without an email).
        if (! $user && $caoUsername) {
            $user = $model->newQuery()->where($columns['username'], $caoUsername)->first();
        }

        $attributes = [
            $columns['cao_user_id'] => $caoUserId,
            $columns['cao_client_id'] => $this->caoClientId,
            $columns['email_verified_at'] => ($raw['email_verified'] ?? false)
                ? ($user->{$columns['email_verified_at']} ?? now())
                : ($user->{$columns['email_verified_at']} ?? null),
            $columns['disabled_at'] => ($raw['active'] ?? true) ? null : now(),
        ];

        if ($user) {
            $user->forceFill($attributes)->save();
            $this->syncProfile($user, $caoUser->getName());

            Log::channel(config('cao-sso.log_channel'))->info('CAO SSO existing user updated', [
                'local_user_id' => $user->getKey(),
                'cao_user_id' => $caoUserId,
                'cao_client_id' => $this->caoClientId,
            ]);

            return $user;
        }

        $user = $model->newQuery()->create(array_merge($attributes, [
            $columns['username'] => $this->generateUniqueUsername($caoUsername ?? $caoEmail),
            $columns['email'] => $caoEmail,
            'password' => Hash::make(Str::random(32)),
        ]));

        $this->syncProfile($user, $caoUser->getName());

        Log::channel(config('cao-sso.log_channel'))->info('CAO SSO new user created', [
            'local_user_id' => $user->getKey(),
            'cao_user_id' => $caoUserId,
            'cao_client_id' => $this->caoClientId,
        ]);

        return $user;
    }

    /**
     * Create or update the related profile display name, when configured.
     */
    protected function syncProfile(object $user, ?string $name): void
    {
        if (! $name || ! config('cao-sso.profile_model')) {
            return;
        }

        $profileModel = config('cao-sso.profile_model');
        $foreignKey = config('cao-sso.profile.user_foreign_key', 'user_id');
        $nameColumn = config('cao-sso.profile.name_column', 'full_name');

        $profileModel::updateOrCreate(
            [$foreignKey => $user->getKey()],
            [$nameColumn => $name]
        );
    }

    /**
     * Return a username that is unique within the users table.
     */
    protected function generateUniqueUsername(?string $base): string
    {
        $column = config('cao-sso.columns.username');

        $base = Str::slug((string) $base)->lower() ?: 'user';
        $username = $base;
        $counter = 1;

        while ($this->userModel()->newQuery()->where($column, $username)->exists()) {
            $username = "{$base}-{$counter}";
            $counter++;
        }

        return $username;
    }

    /**
     * @return Model
     */
    protected function userModel()
    {
        $class = config('cao-sso.user_model');

        return new $class;
    }
}
