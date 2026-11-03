<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search over the data the viewer is allowed to see.
 *
 * PostgreSQL uses the generated `search_vector` column (see the migration),
 * while SQLite (tests) falls back to LIKE. Both paths apply the same
 * membership and history filters, so search can never become an authorization
 * bypass.
 */
class SearchService
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * @return Collection<int, Conversation>
     */
    public function conversations(User $viewer, string $query, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $term = trim($query);

        if ($term === '') {
            return collect();
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        return Conversation::query()
            ->whereHas('members', function (Builder $builder) use ($viewer): void {
                $builder->whereKey($viewer->id)
                    ->whereNull('conversation_members.left_at');
            })
            ->where(function (Builder $builder) use ($viewer, $term): void {
                $this->applyLike($builder, 'conversations.title', $term);

                $builder->orWhereHas('members', function (Builder $members) use ($viewer, $term): void {
                    $members->where('users.id', '!=', $viewer->id)
                        ->where(function (Builder $participant) use ($term): void {
                            $this->applyLike($participant, 'users.username', $term);
                            $participant->orWhere(function (Builder $name) use ($term): void {
                                $this->applyLike($name, 'users.name', $term);
                            });
                        });
                });
            })
            ->with(['lastMessage.sender', 'lastMessage.attachments', 'members'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Message>
     */
    public function messages(
        User $viewer,
        string $query,
        ?string $conversationId = null,
        int $limit = self::DEFAULT_LIMIT,
    ): Collection {
        $term = trim($query);

        if ($term === '') {
            return collect();
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        return Message::query()
            ->select('messages.*')
            ->with(['sender', 'replyTo.sender', 'attachments', 'conversation.members'])
            ->join('conversation_members as viewer_membership', function (JoinClause $join) use ($viewer): void {
                $join->on('viewer_membership.conversation_id', '=', 'messages.conversation_id')
                    ->where('viewer_membership.user_id', '=', $viewer->id)
                    ->whereNull('viewer_membership.left_at');
            })
            ->whereColumn('messages.seq', '>=', 'viewer_membership.history_from_seq')
            ->whereNull('messages.deleted_at')
            ->whereNotNull('messages.body')
            ->when(
                $conversationId !== null,
                fn (Builder $builder): Builder => $builder->where('messages.conversation_id', $conversationId),
            )
            ->where(function (Builder $builder) use ($term): void {
                if ($this->usesFullTextSearch()) {
                    $builder->whereRaw(
                        "messages.search_vector @@ plainto_tsquery('simple', ?)",
                        [$term],
                    );

                    return;
                }

                $this->applyLike($builder, 'messages.body', $term);
            })
            ->orderByDesc('messages.created_at')
            ->limit($limit)
            ->get();
    }

    private function usesFullTextSearch(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Case-insensitive substring match on the current database. Wildcard
     * characters are escaped so a query such as "100%" cannot turn into a scan
     * of everything.
     */
    private function applyLike(Builder $builder, string $column, string $term): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = '%'.$escaped.'%';

        if ($this->usesFullTextSearch()) {
            $builder->whereRaw("{$column} ILIKE ? ESCAPE '\\'", [$pattern]);

            return;
        }

        $builder->where($column, 'like', $pattern);
    }
}
