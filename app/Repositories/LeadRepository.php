<?php

namespace App\Repositories;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeadRepository extends BaseRepository
{
    public function __construct(Lead $model)
    {
        parent::__construct($model);
    }

    public function queryForUser(User $user): Builder
    {
        return Lead::with(['source', 'stage', 'disposition', 'assignedTo', 'center'])
            ->forUser($user);
    }

    public function findByMobile(string $mobile): ?Lead
    {
        return Lead::where('dedup_hash', \App\Support\PhoneNumber::dedupHash($mobile))->first();
    }

    public function findByEmail(string $email): ?Lead
    {
        return Lead::where('email', $email)->first();
    }

    public function findDuplicates(array $data): Collection
    {
        $query = Lead::query();
        $conditions = [];

        if (!empty($data['mobile']) && ($hash = \App\Support\PhoneNumber::dedupHash($data['mobile']))) {
            $conditions[] = ['dedup_hash', $hash];
        }
        if (!empty($data['email'])) {
            $conditions[] = ['email', $data['email']];
        }

        if (empty($conditions)) {
            return collect();
        }

        return $query->where(function ($q) use ($conditions) {
            foreach ($conditions as [$col, $val]) {
                $q->orWhere($col, $val);
            }
        })->get();
    }

    public function getPendingFollowUps(int $userId): Builder
    {
        return Lead::with(['followUps' => fn($q) => $q->pending()])
            ->where('assigned_to', $userId)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now());
    }

    public function getTodayAssigned(int $userId): int
    {
        return Lead::where('assigned_to', $userId)
            ->whereDate('assigned_at', today())
            ->count();
    }

    public function getKanbanData(int $centerId = null): array
    {
        $query = Lead::with(['assignedTo'])
            ->whereNotIn('status', ['junk', 'lost']);

        if ($centerId) {
            $query->where('center_id', $centerId);
        }

        return $query->get()->groupBy('stage_id')->toArray();
    }
}
