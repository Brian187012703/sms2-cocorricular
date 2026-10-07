<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionVote;
use App\Models\ElectionVoter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ElectionController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $elections = Election::with(['club', 'creator'])
            ->withCount('candidates')
            ->withCount('voters')
            ->orderBy('id', 'desc')
            ->get();

        // Check which elections the current user has voted in
        $votedElectionIds = ElectionVoter::where('user_id', $user->id)
            ->pluck('election_id')
            ->toArray();

        $electionsMapped = $elections->map(function ($el) use ($votedElectionIds) {
            $el->has_voted = in_array($el->id, $votedElectionIds);

            return $el;
        });

        $clubs = in_array($user->role, ['ssc', 'admin'])
            ? Club::where('status', 'Active')->orderBy('name')->get()
            : [];

        return Inertia::render('Elections/Index', [
            'elections' => $electionsMapped,
            'clubs' => $clubs,
            'metrics' => [
                'active' => Election::where('status', 'active')->count(),
                'total' => Election::count(),
                'total_votes' => ElectionVote::count(),
            ],
        ]);
    }

    public function show(Election $election)
    {
        $user = Auth::user();
        $election->load(['club', 'candidates' => fn ($q) => $q->withCount('votes')->orderBy('id', 'desc')]);

        $hasVoted = ElectionVoter::where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->exists();

        // Group candidates by position
        $candidatesByPosition = $election->candidates
            ->groupBy('position')
            ->map(function ($candidates) {
                return $candidates->map(function ($c) {
                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                        'position' => $c->position,
                        'party_list' => $c->party,
                        'platform' => $c->platform_tag,
                        'photo' => null,
                        'vote_count' => $c->votes_count,
                    ];
                });
            });

        return Inertia::render('Elections/Show', [
            'election' => $election,
            'candidatesByPosition' => $candidatesByPosition,
            'hasVoted' => $hasVoted,
            'canVote' => $election->canVote($user) && ! $hasVoted,
        ]);
    }

    public function vote(Request $request, Election $election)
    {
        $user = Auth::user();

        if ($user->role !== 'student') {
            return back()->with('error', 'Only eligible students can cast ballots in student governance elections.');
        }

        if (! $election->canVote($user)) {
            return back()->with('error', 'This election is not currently open for voting.');
        }

        // Verify voter has not already voted
        $alreadyVoted = ElectionVoter::where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyVoted) {
            return back()->with('error', 'You have already cast your ballot in this election.');
        }

        $validated = $request->validate([
            'votes' => 'required|array|min:1',
            'votes.*' => 'required|integer|distinct|exists:election_candidates,id',
        ]);

        DB::transaction(function () use ($election, $user, $validated, $request) {
            $election = Election::whereKey($election->id)->lockForUpdate()->firstOrFail();
            if (! $election->canVote($user) || ElectionVoter::where('election_id', $election->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['votes' => 'Voting is closed, you are ineligible, or a ballot has already been recorded.']);
            }
            $candidates = ElectionCandidate::where('election_id', $election->id)->whereIn('status', ['Active', 'Approved'])->whereIn('id', $validated['votes'])->get();
            if ($candidates->count() !== count($validated['votes']) || $candidates->pluck('position')->unique()->count() !== $candidates->count()) {
                throw ValidationException::withMessages(['votes' => 'Choose one eligible candidate per position from this election.']);
            }
            // 1. Record voter participation (Identity tracked here ONLY to prevent double voting)
            ElectionVoter::create([
                'election_id' => $election->id,
                'user_id' => $user->id,
                'voted_at' => now(),
            ]);

            // 2. Cast secret anonymous ballots (Decoupled: zero link to user_id!)
            foreach ($validated['votes'] as $candidateId) {
                $candidate = ElectionCandidate::find($candidateId);
                if ($candidate && $candidate->election_id == $election->id) {
                    $anonymousToken = bin2hex(random_bytes(32));

                    ElectionVote::create([
                        'election_id' => $election->id,
                        'candidate_id' => $candidate->id,
                        'position' => $candidate->position,
                        'vote_hash' => $anonymousToken,
                        'votes_json' => json_encode([$candidate->position => $candidate->id]),
                        'cast_at' => now(),
                    ]);

                    $candidate->increment('votes_count');
                }
            }

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'ELECTION_BALLOT_CAST',
                'details' => "Ballot recorded for election {$election->election_code}",
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('elections.show', $election->id)->with('success', 'Your secret ballot has been cast and officially recorded!');
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (! in_array($user->role, ['ssc', 'admin'])) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'election_type' => 'nullable|string|max:100',
            'starts_at' => 'required|date',
            'closes_at' => 'required|date|after:starts_at',
            'eligible_voters' => 'nullable|integer|min:0',
        ]);

        $code = 'ELEC-'.Str::upper(Str::random(16));

        $election = Election::create([
            'election_code' => $code,
            'club_id' => $validated['club_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'election_type' => $validated['election_type'] ?? 'Student Council',
            'starts_at' => $validated['starts_at'],
            'closes_at' => $validated['closes_at'],
            'status' => 'active',
            'eligible_voters' => User::where('role', 'student')->where('status', 'Active')->whereHas('memberships', fn ($q) => $q->where('club_id', $validated['club_id'])->where('status', 'Active'))->count(),
            'created_by' => $user->id,
        ]);

        return redirect()->route('elections.index')->with('success', "Election {$election->title} opened successfully!");
    }

    public function addCandidate(Request $request, Election $election)
    {
        $user = Auth::user();
        if (! in_array($user->role, ['ssc', 'admin', 'club_adviser'])) {
            abort(403, 'Unauthorized.');
        }

        if ($user->role === 'club_adviser') {
            abort_unless(Club::whereKey($election->club_id)->where('adviser_user_id', $user->id)->exists(), 403);
        }
        abort_if($election->voters()->exists() || ($election->closes_at && $election->closes_at->isPast()), 409, 'Candidates cannot change after voting begins or closes.');
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'position' => 'required|string|max:100',
            'party_list' => 'nullable|string|max:100',
            'platform' => 'nullable|string',
        ]);

        ElectionCandidate::create([
            'election_id' => $election->id,
            'candidate_code' => 'CAND-'.Str::upper(Str::random(16)),
            'user_id' => null,
            'name' => $validated['name'],
            'position' => $validated['position'],
            'party' => $validated['party_list'] ?? 'Independent',
            'platform_tag' => $validated['platform'] ?? '',
            'votes_count' => 0,
            'status' => 'Approved',
        ]);

        return back()->with('success', 'Candidate registered successfully.');
    }
}
