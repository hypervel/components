<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Container\Container;
use Hypervel\Fortify\Contracts\ProfileInformationUpdatedResponse;
use Hypervel\Fortify\Contracts\UpdatesUserProfileInformation;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Str;

class ProfileInformationController extends Controller
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {
    }

    /**
     * Update the user's profile information.
     */
    public function update(Request $request, UpdatesUserProfileInformation $updater): ProfileInformationUpdatedResponse
    {
        if ($this->config->boolean('fortify.lowercase_usernames', false) && $request->has(Fortify::username())) {
            $request->merge([
                Fortify::username() => Str::lower((string) $request->{Fortify::username()}),
            ]);
        }

        /** @var Authenticatable $user */
        $user = $request->user();

        $updater->update($user, $request->all());

        return $this->container->make(ProfileInformationUpdatedResponse::class);
    }
}
