<?php

namespace App\Http\Resources;

use App\Models\E2eeIdentityKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin E2eeIdentityKey
 */
class E2eeIdentityKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'public_key' => $this->public_key,
            'algorithm' => $this->algorithm,
            'fingerprint' => $this->fingerprint,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
