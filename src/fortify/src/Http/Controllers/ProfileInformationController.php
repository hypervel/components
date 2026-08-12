<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Fortify\Contracts\ProfileInformationUpdatedResponse;
use Hypervel\Fortify\Contracts\UpdatesUserProfileInformation;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Str;

class ProfileInformationController extends Controller
{
    /**
     * Update the user's profile information.
     */
    public function update(Request $request, UpdatesUserProfileInformation $updater): ProfileInformationUpdatedResponse
    {
        $config = app(Config::class);

        if ($config->boolean('fortify.lowercase_usernames') && $request->has(Fortify::username())) {
            $request->merge([
                Fortify::username() => Str::lower((string) $request->{Fortify::username()}),
            ]);
        }

        /** @var Authenticatable $user */
        $user = $request->user();

        $updater->update($user, $request->all());

        return app(ProfileInformationUpdatedResponse::class);
    }
}
