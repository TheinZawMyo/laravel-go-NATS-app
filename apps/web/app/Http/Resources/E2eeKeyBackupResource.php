<?php

namespace App\Http\Resources;

use App\Models\E2eeKeyBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin E2eeKeyBackup
 */
class E2eeKeyBackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ciphertext' => $this->ciphertext,
            'salt' => $this->salt,
            'iv' => $this->iv,
            'kdf' => $this->kdf,
            'iterations' => $this->iterations,
            'fingerprint' => $this->fingerprint,
            'updated_at' => $this->updated_at,
        ];
    }
}
