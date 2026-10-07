<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BudgetRequest;
use App\Models\Club;
use App\Models\WorkflowHistory;
use App\Support\VisibleRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $role = $user->role;
        abort_if($role === 'student', 403);

        $query = VisibleRecords::budgets($user)->with(['club', 'requester', 'disburser']);

        if ($role === 'club_adviser') {
            $query->whereIn('club_id', Club::where('adviser_user_id', $user->id)->select('id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                    ->orWhere('disbursement_reference', 'like', "%{$s}%");
            });
        }

        $budgets = $query->orderBy('id', 'desc')->paginate(15)->withQueryString();

        $clubs = [];
        if ($role === 'club_adviser') {
            $clubs = Club::where('adviser_user_id', $user->id)->get();
        } elseif (in_array($role, ['ssc', 'admin'])) {
            $clubs = Club::where('status', 'Active')->orderBy('name')->get();
        }

        return Inertia::render('Budgets/Index', [
            'budgets' => $budgets,
            'clubs' => $clubs,
            'filters' => $request->only(['status', 'search']),
            'metrics' => [
                'total_requested' => (float) VisibleRecords::budgets($user)->sum('amount'),
                'total_disbursed' => (float) VisibleRecords::budgets($user)->whereNotNull('disbursed_at')->sum('final_approved_amount'),
                'pending_ssc' => VisibleRecords::budgets($user)->where('status', 'Pending SSC')->count(),
                'pending_admin' => VisibleRecords::budgets($user)->where('status', 'Pending Admin')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if ($user->role === 'student') {
            abort(403, 'Students are not authorized to submit institutional budget requisitions.');
        }

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'line_items' => 'nullable|string',
            'amount' => 'required|numeric|min:1',
        ]);

        if ($user->role === 'club_adviser') {
            if (! Club::whereKey($validated['club_id'])->where('adviser_user_id', $user->id)->exists()) {
                abort(403, 'You can only submit budget requisitions for your assigned club.');
            }
        }

        $initialStatus = $user->role === 'club_adviser' ? 'Pending SSC' : 'Pending Adviser';
        $budget = BudgetRequest::create([
            'club_id' => $validated['club_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'line_items' => $validated['line_items'] ?? '',
            'amount' => $validated['amount'],
            'recommended_amount' => null,
            'status' => $initialStatus,
            'requested_by' => $user->id,
        ]);

        WorkflowHistory::create([
            'module' => 'budget',
            'record_id' => $budget->id,
            'from_status' => 'None',
            'to_status' => $initialStatus,
            'action' => 'Requisition Submitted',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => "Amount: PHP {$validated['amount']}",
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'BUDGET_SUBMITTED',
            'details' => "Requisition: {$budget->title} (PHP {$budget->amount})",
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('budgets.index')->with('success', 'Budget requisition submitted successfully for SSC review.');
    }

    public function endorseAdviser(Request $request, BudgetRequest $budget)
    {
        $user = $request->user();
        abort_unless($user->role === 'club_adviser' && Club::whereKey($budget->club_id)->where('adviser_user_id', $user->id)->exists(), 403);
        abort_unless($budget->status === 'Pending Adviser', 409);
        $budget->update(['status' => 'Pending SSC']);
        WorkflowHistory::create([
            'module' => 'budget', 'record_id' => $budget->id,
            'from_status' => 'Pending Adviser', 'to_status' => 'Pending SSC',
            'action' => 'Adviser Endorsement', 'performed_by' => $user->id,
            'actor_role' => $user->role, 'remarks' => $request->input('remarks'),
        ]);

        return back()->with('success', 'Endorsed for SSC review.');
    }

    public function endorseSsc(Request $request, BudgetRequest $budget)
    {
        $user = Auth::user();
        if ($user->role !== 'ssc') {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'recommended_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        abort_unless($budget->status === 'Pending SSC', 409, 'Budget is not awaiting SSC review.');
        $previous = $budget->status;
        $budget->update([
            'recommended_amount' => $validated['recommended_amount'],
            'status' => 'Pending Admin',
            'notes' => $validated['notes'] ?? 'Vetted by SSC Finance Committee',
        ]);

        WorkflowHistory::create([
            'module' => 'budget',
            'record_id' => $budget->id,
            'from_status' => $previous,
            'to_status' => 'Pending Admin',
            'action' => 'SSC Budget Review',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => "Recommended: PHP {$validated['recommended_amount']}",
        ]);

        return back()->with('success', 'Budget requisition vetted and forwarded to Admin clearance.');
    }

    public function approveAdmin(Request $request, BudgetRequest $budget)
    {
        $user = Auth::user();
        if ($user->role !== 'admin') {
            abort(403, 'Only Administrators can approve budget requisitions.');
        }

        $validated = $request->validate([
            'final_approved_amount' => 'required|numeric|min:0',
        ]);

        abort_unless($budget->status === 'Pending Admin', 409, 'Budget is not awaiting admin clearance.');
        $previous = $budget->status;
        $budget->update([
            'final_approved_amount' => $validated['final_approved_amount'],
            'status' => 'Approved',
        ]);

        WorkflowHistory::create([
            'module' => 'budget',
            'record_id' => $budget->id,
            'from_status' => $previous,
            'to_status' => 'Approved',
            'action' => 'Admin Approval',
            'performed_by' => $user->id,
            'actor_role' => 'admin',
            'remarks' => "Final Approved: PHP {$validated['final_approved_amount']}",
        ]);

        return back()->with('success', 'Budget approved successfully. Ready for disbursement.');
    }

    public function disburse(Request $request, BudgetRequest $budget)
    {
        $user = Auth::user();
        if ($user->role !== 'admin') {
            abort(403, 'Only Administrators can record disbursements.');
        }

        $validated = $request->validate([
            'disbursement_reference' => 'required|string|max:100',
        ]);

        abort_unless($budget->status === 'Approved' && ! $budget->disbursed_at, 409, 'Only approved, unpaid budgets can be disbursed.');
        $budget->update([
            'disbursed_at' => now(),
            'disbursement_reference' => $validated['disbursement_reference'],
            'disbursed_by' => $user->id,
            'status' => 'Disbursed',
        ]);

        WorkflowHistory::create([
            'module' => 'budget',
            'record_id' => $budget->id,
            'from_status' => 'Approved',
            'to_status' => 'Disbursed',
            'action' => 'Disbursement Released',
            'performed_by' => $user->id,
            'actor_role' => 'admin',
            'remarks' => "Ref: {$validated['disbursement_reference']}",
        ]);

        return back()->with('success', 'Disbursement recorded and transaction reference posted.');
    }
}
