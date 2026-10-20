<?php

namespace Cao\LaravelCaoSso\Providers;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

class CaoProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     *
     * @var array
     */
    protected $scopes = [];

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ',';

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(config('cao-sso.authorize_url'), $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl(): string
    {
        return config('cao-sso.token_url');
    }

    /**
     * {@inheritdoc}
     *
     * Socialite expects the raw user payload (array) here and will pass it to
     * mapUserToObject() to build the Two\User instance.
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get(
            config('cao-sso.userinfo_url'),
            [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ],
            ]
        );

        return json_decode($response->getBody(), true) ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessTokenResponse($code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'form_params' => $this->getTokenFields($code),
        ]);

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            throw new \RuntimeException(
                'CAO token exchange failed: '.($data['error_description'] ?? $data['error'] ?? 'Unknown error')
            );
        }

        return $data;
    }

    /**
     * Get the access token fields for the token request.
     *
     * @param  string  $code
     */
    protected function getTokenFields($code): array
    {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUrl,
            'code' => $code,
        ];
    }

    /**
     * Set the scopes for the provider.
     *
     * @param  array|string  $scopes
     */
    public function scopes($scopes): static
    {
        $this->scopes = is_array($scopes) ? $scopes : explode($this->scopeSeparator, $scopes);

        return $this;
    }

    /**
     * Get the default scopes for the provider.
     */
    protected function getDefaultScopes(): array
    {
        return config('cao-sso.scopes', []);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? $user['id'] ?? null,
            'nickname' => $user['username'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $user['avatar'] ?? null,
            'email_verified' => $user['email_verified'] ?? false,
        ]);
    }
}
