<?php

namespace App\Http\Requests\Admin;

/**
 * A PUT replaces the full service record with the same shape as create, so
 * this reuses StoreServiceRequest's rules and validator as-is.
 */
class UpdateServiceRequest extends StoreServiceRequest
{
    //
}
