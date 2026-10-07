<?php

namespace App\Support;

use App\Models\Achievement;
use App\Models\AttendanceLog;
use App\Models\BudgetRequest;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Event;
use App\Models\OrgAnnouncement;
use App\Models\Student;
use App\Models\User;

class VisibleRecords
{
    public static function clubIds(User $user)
    {
        return Club::where('adviser_user_id', $user->id)->select('id');
    }

    public static function students(User $user)
    {
        return Student::query()->when($user->role === 'student', fn ($q) => $q->where('user_id', $user->id))
            ->when($user->role === 'club_adviser', fn ($q) => $q->whereIn('user_id', ClubMembership::whereIn('club_id', self::clubIds($user))->select('user_id')));
    }

    public static function events(User $user)
    {
        return Event::query()->when($user->role === 'student', fn ($q) => $q->whereIn('status', ['Approved', 'Upcoming', 'Completed']))
            ->when($user->role === 'club_adviser', fn ($q) => $q->where(fn ($q) => $q->whereIn('club_id', self::clubIds($user))->orWhereIn('status', ['Approved', 'Upcoming', 'Completed'])));
    }

    public static function achievements(User $user)
    {
        return Achievement::query()->when($user->role === 'student', fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['Approved', 'Verified'])->orWhere('submitted_by', $user->id)))
            ->when($user->role === 'club_adviser', fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['Approved', 'Verified'])->orWhereIn('club_id', self::clubIds($user))));
    }

    public static function budgets(User $user)
    {
        return BudgetRequest::query()->when($user->role === 'student', fn ($q) => $q->whereRaw('1 = 0'))
            ->when($user->role === 'club_adviser', fn ($q) => $q->whereIn('club_id', self::clubIds($user)));
    }

    public static function memberships(User $user)
    {
        return ClubMembership::query()->when($user->role === 'student', fn ($q) => $q->where('user_id', $user->id))
            ->when($user->role === 'club_adviser', fn ($q) => $q->whereIn('club_id', self::clubIds($user)));
    }

    public static function attendance(User $user)
    {
        return AttendanceLog::query()->when($user->role === 'student', fn ($q) => $q->where('user_id', $user->id))
            ->when($user->role === 'club_adviser', fn ($q) => $q->whereHas('event', fn ($q) => $q->whereIn('club_id', self::clubIds($user))));
    }

    public static function announcements(User $user)
    {
        $query = OrgAnnouncement::query();
        if (in_array($user->role, ['admin', 'ssc'], true)) {
            return $query;
        }
        $groups = $user->role === 'student' ? ['All', 'All Members', 'Students'] : ['All', 'All Members', 'Advisers', 'Faculty'];
        $clubs = $user->role === 'student'
            ? ClubMembership::where('user_id', $user->id)->where('status', 'Active')->select('club_id') : self::clubIds($user);

        return $query->whereIn('status', ['Published', 'Active'])->whereIn('target_group', $groups)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('club_id')->orWhereIn('club_id', $clubs));
    }
}
