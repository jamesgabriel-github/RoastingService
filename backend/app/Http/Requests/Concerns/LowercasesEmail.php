<?php

namespace App\Http\Requests\Concerns;

trait LowercasesEmail
{
    /**
     * Lowercase the `email` input, if present, so uniqueness and lookups are
     * case-insensitive.
     */
    protected function lowercaseEmail(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => strtolower($email)]);
        }
    }
}
