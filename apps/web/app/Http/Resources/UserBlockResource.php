<?php

namespace App\Http\Resources;

use App\Models\UserBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserBlock
 */
class UserBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => (new PublicUserResource($this->blocked))->resolve($request),
            'reason' => $this->reason,
            'created_at' => $this->created_at,
        ];
    }
}
