<?php

namespace App\Repositories;

use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Builder;

class FollowUpRepository extends BaseRepository
{
    public function __construct(FollowUp $model)
    {
        parent::__construct($model);
    }

    public function queryForUser(int $userId): Builder
    {
        return FollowUp::with(['lead', 'disposition'])
            ->where('user_id', $userId);
    }

    public function todayForUser(int $userId): Builder
    {
        return $this->queryForUser($userId)->today();
    }

    public function overdueForUser(int $userId): Builder
    {
        return $this->queryForUser($userId)->overdue();
    }

    public function pendingReminders(): Builder
    {
        return FollowUp::with('lead')
            ->pending()
            ->where('reminder_sent', false)
            ->where('scheduled_at', '<=', now()->addMinutes(30));
    }
}
