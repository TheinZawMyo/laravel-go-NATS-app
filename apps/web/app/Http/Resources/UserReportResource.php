<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\UserReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserReport
 */
class UserReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason->value,
            'details' => $this->details,
            'status' => $this->status->value,
            'action_taken' => $this->action_taken,
            'resolution_note' => $this->resolution_note,
            'reviewed_at' => $this->reviewed_at,
            'reporter' => $this->whenLoaded(
                'reporter',
                fn () => $this->summary($this->reporter),
            ),
            'reported_user' => $this->whenLoaded(
                'reportedUser',
                fn () => $this->reportedUser !== null
                    ? $this->summary($this->reportedUser)
                    : null,
            ),
            'reported_message' => $this->whenLoaded(
                'reportedMessage',
                fn () => $this->reportedMessage !== null
                    ? (new MessageResource($this->reportedMessage))->resolve($request)
                    : null,
            ),
            'reviewer' => $this->whenLoaded(
                'reviewer',
                fn () => $this->reviewer !== null
                    ? $this->summary($this->reviewer)
                    : null,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summary(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar_url' => $user->avatar_url,
        ];
    }
}
