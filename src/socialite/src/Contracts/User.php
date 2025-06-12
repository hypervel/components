<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Contracts;

interface User
{
    /**
     * Get the unique identifier for the user.
     */
    public function getId(): mixed;

    /**
     * Get the nickname / username for the user.
     */
    public function getNickname(): ?string;

    /**
     * Get the full name of the user.
     */
    public function getName(): ?string;

    /**
     * Get the e-mail address of the user.
     */
    public function getEmail(): ?string;

    /**
     * Get the avatar / image URL for the user.
     */
    public function getAvatar(): ?string;
}
