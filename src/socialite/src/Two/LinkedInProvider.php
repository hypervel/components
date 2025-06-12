<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;

class LinkedInProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['r_liteprofile', 'r_emailaddress'];

    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ' ';

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('https://www.linkedin.com/oauth/v2/authorization', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }

    protected function getUserByToken(string $token): array
    {
        $basicProfile = $this->getBasicProfile($token);
        $emailAddress = $this->getEmailAddress($token);

        return array_merge($basicProfile, $emailAddress);
    }

    /**
     * Get the basic profile fields for the user.
     */
    protected function getBasicProfile(string $token): array
    {
        $fields = ['id', 'firstName', 'lastName', 'profilePicture(displayImage~:playableStreams)'];

        if (in_array('r_liteprofile', $this->getScopes())) {
            array_push($fields, 'vanityName');
        }

        $response = $this->getHttpClient()->get('https://api.linkedin.com/v2/me', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer ' . $token,
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'projection' => '(' . implode(',', $fields) . ')',
            ],
        ]);

        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * Get the email address for the user.
     */
    protected function getEmailAddress(string $token): array
    {
        $response = $this->getHttpClient()->get('https://api.linkedin.com/v2/emailAddress', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer ' . $token,
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'q' => 'members',
                'projection' => '(elements*(handle~))',
            ],
        ]);

        return (array) Arr::get((array) json_decode((string) $response->getBody(), true), 'elements.0.handle~');
    }

    protected function mapUserToObject(array $user): User
    {
        $preferredLocale = Arr::get($user, 'firstName.preferredLocale.language') . '_' . Arr::get($user, 'firstName.preferredLocale.country');
        $firstName = Arr::get($user, 'firstName.localized.' . $preferredLocale);
        $lastName = Arr::get($user, 'lastName.localized.' . $preferredLocale);

        $images = (array) Arr::get($user, 'profilePicture.displayImage~.elements', []);
        $avatar = Arr::first($images, function ($image) {
            return (
                $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['storageSize']['width']
                ?? $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['displaySize']['width']
            ) === 100;
        });
        $originalAvatar = Arr::first($images, function ($image) {
            return (
                $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['storageSize']['width']
                ?? $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['displaySize']['width']
            ) === 800;
        });

        return (new User())->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => null,
            'name' => $firstName . ' ' . $lastName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => Arr::get($user, 'emailAddress'),
            'avatar' => Arr::get($avatar, 'identifiers.0.identifier'),
            'avatar_original' => Arr::get($originalAvatar, 'identifiers.0.identifier'),
        ]);
    }
}
