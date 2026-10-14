<?php

namespace App\Lobby;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A player known only by the token cookie in their browser. Used to
 * authorize their private and presence channels.
 */
final class Guest implements Authenticatable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
