# Starter Kits

- [Introduction](#introduction)
- [Creating an Application Using the Starter Kit](#creating-an-application)
- [React](#react)
- [Starter Kit Customization](#starter-kit-customization)
    - [React](#react-customization)
- [Authentication](#authentication)
    - [Enabling and Disabling Features](#enabling-and-disabling-features)
    - [Customizing User Creation and Password Reset](#customizing-actions)
    - [Two-Factor Authentication](#two-factor-authentication)
    - [Rate Limiting](#rate-limiting)
- [Inertia SSR](#inertia-ssr)
- [Frequently Asked Questions](#faqs)

<a name="introduction"></a>
## Introduction

To give you a head start building your new Hypervel application, the Hypervel team maintains a React starter kit. The starter kit includes the routes, controllers, views, and React components you need to register and authenticate your application's users. The starter kit uses [Hypervel Fortify](/docs/{{version}}/fortify) to provide authentication.

The Hypervel team currently maintains a React starter kit because that is what the team uses internally. While you are welcome to use this starter kit, it is not required. You are free to build your own application from the ground up by simply installing a fresh copy of Hypervel. Either way, we know you will build something great!

<a name="creating-an-application"></a>
## Creating an Application Using the Starter Kit

To create a new Hypervel application using the React starter kit, use Composer's `create-project` command:

```shell
composer create-project hypervel/react-starter-kit my-app
```

After creating your Hypervel application, install its frontend dependencies and build the frontend assets:

```shell
cd my-app
npm install
npm run build
```

You may then start the Hypervel development server and frontend asset watcher using the `dev` Composer script:

```shell
composer dev
```

Once you have started the Hypervel development server, your application will be accessible in your web browser at [http://localhost:8000](http://localhost:8000).

<a name="react"></a>
## React

The React starter kit provides a modern starting point for building Hypervel applications with a React frontend using [Inertia](https://inertiajs.com).

Inertia allows you to build modern, single-page React applications using classic server-side routing and controllers. This lets you enjoy the frontend power of React combined with the backend productivity of Hypervel and lightning-fast Vite compilation.

The React starter kit uses React 19, TypeScript, Tailwind, and the base UI variant of the [shadcn/ui](https://ui.shadcn.com) component library.

<a name="starter-kit-customization"></a>
## Starter Kit Customization

<a name="react-customization"></a>
### React

The React starter kit is built with Inertia, React 19, Tailwind, and [shadcn/ui](https://ui.shadcn.com). All of the backend and frontend code exists within your application to allow for full customization.

The majority of the frontend code is located in the `resources/js` directory. You are free to modify any of the code to customize the appearance and behavior of your application:

```text
resources/js/
├── components/    # Reusable React components
├── hooks/         # React hooks
├── layouts/       # Application layouts
├── lib/           # Utility functions and configuration
├── pages/         # Page components
└── types/         # TypeScript definitions
```

To publish additional shadcn components, first [find the component you want to publish](https://ui.shadcn.com). Then, publish the component using `npx`:

```shell
npx shadcn@latest add switch
```

In this example, the command will publish the Switch component to `resources/js/components/ui/switch.tsx`. Once the component has been published, you can use it in any of your pages:

```jsx
import { Switch } from "@/components/ui/switch"

const MyPage = () => {
  return (
    <div>
      <Switch />
    </div>
  );
};

export default MyPage;
```

<a name="react-available-layouts"></a>
#### Available Layouts

The React starter kit includes two different primary layouts for you to choose from: a "sidebar" layout and a "header" layout. The sidebar layout is the default, but you can switch to the header layout by modifying the layout that is imported at the top of your application's `resources/js/layouts/app-layout.tsx` file:

```js
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout'; // [tl! remove]
import AppLayoutTemplate from '@/layouts/app/app-header-layout'; // [tl! add]
```

<a name="react-sidebar-variants"></a>
#### Sidebar Variants

The sidebar layout includes three different variants: the default sidebar variant, the "inset" variant, and the "floating" variant. You may choose the variant you like best by modifying the `resources/js/components/app-sidebar.tsx` component:

```text
<Sidebar collapsible="icon" variant="sidebar"> [tl! remove]
<Sidebar collapsible="icon" variant="inset"> [tl! add]
```

<a name="react-authentication-page-layout-variants"></a>
#### Authentication Page Layout Variants

The authentication pages included with the React starter kit, such as the login page and registration page, also offer three different layout variants: "simple", "card", and "split".

To change your authentication layout, modify the layout that is imported at the top of your application's `resources/js/layouts/auth-layout.tsx` file:

```js
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout'; // [tl! remove]
import AuthLayoutTemplate from '@/layouts/auth/auth-split-layout'; // [tl! add]
```

<a name="authentication"></a>
## Authentication

The React starter kit uses [Hypervel Fortify](/docs/{{version}}/fortify) to handle authentication. Fortify provides routes, controllers, and logic for login, registration, password reset, email verification, and more.

Fortify automatically registers the following authentication routes based on the features that are enabled in your application's `config/fortify.php` configuration file:

| Route                              | Method | Description                         |
| ---------------------------------- | ------ | ----------------------------------- |
| `/login`                           | `GET`  | Display login form                  |
| `/login`                           | `POST` | Authenticate user                   |
| `/logout`                          | `POST` | Log user out                        |
| `/register`                        | `GET`  | Display registration form           |
| `/register`                        | `POST` | Create new user                     |
| `/forgot-password`                 | `GET`  | Display password reset request form |
| `/forgot-password`                 | `POST` | Send password reset link            |
| `/reset-password/{token}`          | `GET`  | Display password reset form         |
| `/reset-password`                  | `POST` | Update password                     |
| `/email/verify`                    | `GET`  | Display email verification notice   |
| `/email/verify/{id}/{hash}`        | `GET`  | Verify email address                |
| `/email/verification-notification` | `POST` | Resend verification email           |
| `/user/confirm-password`           | `GET`  | Display password confirmation form  |
| `/user/confirm-password`           | `POST` | Confirm password                    |
| `/two-factor-challenge`            | `GET`  | Display 2FA challenge form          |
| `/two-factor-challenge`            | `POST` | Verify 2FA code                     |

The `php artisan route:list` Artisan command can be used to display all of the routes in your application.

<a name="enabling-and-disabling-features"></a>
### Enabling and Disabling Features

You can control which Fortify features are enabled in your application's `config/fortify.php` configuration file:

```php
use Hypervel\Fortify\Features;

'features' => [
    Features::registration(),
    Features::resetPasswords(),
    Features::emailVerification(),
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]),
],
```

To disable a feature, comment out or remove that feature entry from the `features` array. For example, remove `Features::registration()` to disable public registration.

When using the React starter kit, you will also need to remove any references to the disabled feature's routes in your frontend code. For example, if you disable email verification, you should remove the imports and references to the `verification` routes in your React components. This is necessary because the starter kit uses Wayfinder for type-safe routing, which generates route definitions at build time. If you reference routes that no longer exist, your application will fail to build.

<a name="customizing-actions"></a>
### Customizing User Creation and Password Reset

When a user registers or resets their password, Fortify invokes action classes located in your application's `app/Actions/Fortify` directory:

| File                          | Description                          |
| ----------------------------- | ------------------------------------ |
| `CreateNewUser.php`           | Validates and creates new users      |
| `ResetUserPassword.php`       | Validates and updates user passwords |
| `PasswordValidationRules.php` | Defines password validation rules    |

For example, to customize your application's registration logic, you should edit the `CreateNewUser` action:

```php
public function create(array $input): User
{
    Validator::make($input, [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users'],
        'phone' => ['required', 'string', 'max:20'], // [tl! add]
        'password' => $this->passwordRules(),
    ])->validate();

    return User::create([
        'name' => $input['name'],
        'email' => $input['email'],
        'phone' => $input['phone'], // [tl! add]
        'password' => Hash::make($input['password']),
    ]);
}
```

<a name="two-factor-authentication"></a>
### Two-Factor Authentication

The React starter kit includes built-in two-factor authentication (2FA), allowing users to secure their accounts using any TOTP-compatible authenticator app. 2FA is enabled by default via `Features::twoFactorAuthentication()` in your application's `config/fortify.php` configuration file.

The `confirm` option requires users to verify a code before 2FA is fully enabled, while `confirmPassword` requires password confirmation before enabling or disabling 2FA. For more details, see [Fortify's two-factor authentication documentation](/docs/{{version}}/fortify#two-factor-authentication).

<a name="rate-limiting"></a>
### Rate Limiting

Rate limiting prevents brute-forcing and repeated login attempts from overwhelming your authentication endpoints. You can customize Fortify's rate limiting behavior in your application's `FortifyServiceProvider`:

```php
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Support\Facades\RateLimiter;

RateLimiter::for('login', function ($request) {
    return Limit::perMinute(5)->by($request->email . $request->ip());
});
```

<a name="inertia-ssr"></a>
## Inertia SSR

The React starter kit is compatible with Inertia's [server-side rendering](https://inertiajs.com/server-side-rendering) capabilities. SSR does not require a different starter kit. The same application may be served using normal client-side Inertia rendering or with an Inertia SSR server running alongside your Hypervel application.

To build an Inertia SSR compatible bundle for your application, run the `build:ssr` command:

```shell
npm run build:ssr
```

For convenience, a `composer dev:ssr` command is also available. This command will start the Hypervel development server and Inertia SSR server after building an SSR compatible bundle for your application, allowing you to test your application locally using Inertia's server-side rendering engine:

```shell
composer dev:ssr
```

You may also start the SSR server directly using the `inertia:start-ssr` Artisan command:

```shell
php artisan inertia:start-ssr
```

<a name="faqs"></a>
## Frequently Asked Questions

<a name="faq-upgrade"></a>
### How do I upgrade?

Every starter kit gives you a solid starting point for your next application. With full ownership of the code, you can tweak, customize, and build your application exactly as you envision. However, there is no need to update the starter kit itself.

<a name="faq-enable-email-verification"></a>
### How do I enable email verification?

Email verification can be added by uncommenting the `MustVerifyEmail` import in your `App/Models/User.php` model and ensuring the model implements the `MustVerifyEmail` interface:

```php
<?php

namespace App\Models;

use Hypervel\Contracts\Auth\MustVerifyEmail;
// ...

class User extends Authenticatable implements MustVerifyEmail
{
    // ...
}
```

After registration, users will receive a verification email. To restrict access to certain routes until the user's email address is verified, add the `verified` middleware to the routes:

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});
```

<a name="faq-modify-email-template"></a>
### How do I modify the default email template?

You may want to customize the default email template to better align with your application's branding. To modify this template, you should publish the email views to your application with the following command:

```shell
php artisan vendor:publish --tag=hypervel-mail
```

This will generate several files in `resources/views/vendor/mail`. You can modify any of these files as well as the `resources/views/vendor/mail/themes/default.css` file to change the look and appearance of the default email template.
