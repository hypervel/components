<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack\BlockKit\Elements\Selects;

use Hypervel\Notifications\Slack\BlockKit\Elements\Traits\GeneratesDefaultIds;

class UsersSelectElement extends SelectElement
{
    use GeneratesDefaultIds;

    /**
     * The initially selected user, if applicable.
     */
    private ?string $initialUser = null;

    /**
     * Create a new users select element instance.
     */
    public function __construct(?string $text = null)
    {
        $this->id($this->resolveDefaultId('users_select_', $text));
    }

    /**
     * Specify the ID of the user that should be selected by default.
     */
    public function initialUser(string $value): static
    {
        $this->initialUser = $value;

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return array_filter(array_merge([
            'type' => 'users_select',
            'initial_user' => $this->initialUser,
        ], parent::toArray()), fn ($value): bool => $value !== null);
    }
}
