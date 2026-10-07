<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ElectionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\WebAuthController;
use App\Http\Middleware\AtomicWorkflow;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Laravel 12 + Inertia / Vue Stack)
| Complete Role Process Integration: Student, Club Adviser, SSC, Admin
|--------------------------------------------------------------------------
*/

// Root Entry Point
Route::get('/', function (Request $request) {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

// Authentication Routes
Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:6,1')->name('login.post');
Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');

// Authenticated Application Routes (Protected via web auth guard)
Route::middleware(['auth', EnsureActiveAccount::class, AtomicWorkflow::class])->group(function () {
    // 1. Dashboard Overview
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/students', [DashboardController::class, 'students'])->name('students.index');
    Route::get('/clubs', [DashboardController::class, 'clubs'])->name('clubs.index');
    Route::get('/achievements', [DashboardController::class, 'achievements'])->name('achievements.index');

    // 2. Events & Activities (Conflict Detection + 3-Stage Approval)
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::post('/events/check-conflict', [EventController::class, 'checkConflict'])->name('events.check-conflict');
    Route::post('/events/{event}/endorse-adviser', [EventController::class, 'endorseAdviser'])->name('events.endorse-adviser');
    Route::post('/events/{event}/endorse-ssc', [EventController::class, 'endorseSsc'])->name('events.endorse-ssc');
    Route::post('/events/{event}/approve-admin', [EventController::class, 'approveAdmin'])->name('events.approve-admin');
    Route::post('/events/{event}/reject', [EventController::class, 'reject'])->name('events.reject');

    // 3. Budget & Finance Requisitions (Line-Item Vetting & Disbursement)
    Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('/budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::post('/budgets/{budget}/endorse-adviser', [BudgetController::class, 'endorseAdviser'])->name('budgets.endorse-adviser');
    Route::post('/budgets/{budget}/endorse-ssc', [BudgetController::class, 'endorseSsc'])->name('budgets.endorse-ssc');
    Route::post('/budgets/{budget}/approve-admin', [BudgetController::class, 'approveAdmin'])->name('budgets.approve-admin');
    Route::post('/budgets/{budget}/disburse', [BudgetController::class, 'disburse'])->name('budgets.disburse');

    // 4. Student Elections & Secret Ballot Voting
    Route::get('/elections', [ElectionController::class, 'index'])->name('elections.index');
    Route::get('/elections/{election}', [ElectionController::class, 'show'])->name('elections.show');
    Route::post('/elections', [ElectionController::class, 'store'])->name('elections.store');
    Route::post('/elections/{election}/vote', [ElectionController::class, 'vote'])->name('elections.vote');
    Route::post('/elections/{election}/candidates', [ElectionController::class, 'addCandidate'])->name('elections.candidates.store');

    // 5. Attendance & QR Operations
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/scanner', [AttendanceController::class, 'scanner'])->name('attendance.scanner');
    Route::post('/attendance/scan', [AttendanceController::class, 'recordScan'])->name('attendance.scan');

    // 6. Club Roster & Membership Applications (3-Stage Approval)
    Route::get('/roster', [RosterController::class, 'index'])->name('roster.index');
    Route::post('/roster/apply', [RosterController::class, 'apply'])->name('roster.apply');
    Route::post('/roster/{membership}/endorse-adviser', [RosterController::class, 'endorseAdviser'])->name('roster.endorse-adviser');
    Route::post('/roster/{membership}/approve-ssc', [RosterController::class, 'approveSsc'])->name('roster.approve-ssc');
    Route::post('/roster/{membership}/approve-admin', [RosterController::class, 'approveAdmin'])->name('roster.approve-admin');
    Route::post('/roster/{membership}/reject', [RosterController::class, 'reject'])->name('roster.reject');

    // 7. Announcements & Bulletins
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');

    // 8. Administrative Central Governance
    Route::get('/admin/users', [AdminController::class, 'users'])->name('admin.users');
    Route::post('/admin/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
    Route::post('/admin/users/{targetUser}/toggle-status', [AdminController::class, 'toggleStatus'])->name('admin.users.toggle');
    Route::get('/admin/audit', [AdminController::class, 'audit'])->name('admin.audit');
});
