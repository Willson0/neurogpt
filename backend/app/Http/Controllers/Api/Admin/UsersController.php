<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InsufficientBalance;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnly;
use App\Models\Generation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** People, their balances and payments, and the numbers of the dashboard. */
class UsersController extends Controller
{
    public static function search(Builder $q, string $term): void
    {
        $like = '%'.addcslashes(ltrim($term, '@'), '%_\\').'%';
        $q->where(function (Builder $w) use ($term, $like) {
            if (ctype_digit($term)) {
                $w->where('telegram_id', $term)->orWhere('users.id', $term);
            } elseif (preg_match('/^#(\d+)$/', $term, $m)) {
                $w->where('users.id', $m[1]);
            } else {
                $w->where('username', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like);
            }
        });
    }

    public function index(Request $request)
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:64'], 'filter' => ['nullable', Rule::in(['paid', 'blocked'])]]);

        $q = User::query()->withCount(['generations as created' => fn ($g) => $g->where('status', 'done')]);
        if (trim((string) ($f['q'] ?? '')) !== '') {
            self::search($q, trim($f['q']));
        }
        if (($f['filter'] ?? null) === 'blocked') {
            $q->where('is_blocked', true);
        }
        if (($f['filter'] ?? null) === 'paid') {
            $q->where('plan', '!=', 'free')->where('plan_expires_at', '>', now());
        }
        $total = (clone $q)->count();
        $page = $q->latest('id')->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => $this->user($u)),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'summary' => ['total' => $total],
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'is_blocked' => ['sometimes', 'boolean'],
            'plan' => ['sometimes', Rule::in(array_keys(config('neurogpt.plans')))],
            'plan_days' => ['sometimes', 'integer', 'between:1,3660'],
        ]);
        if (! empty($data['is_blocked']) && AdminOnly::allows($user)) {
            throw ValidationException::withMessages(['is_blocked' => 'Администратора заблокировать нельзя']);
        }
        if (isset($data['plan'])) {
            $user->plan = $data['plan'];
            $user->plan_expires_at = $data['plan'] === 'free' ? null : now()->addDays((int) ($data['plan_days'] ?? 30));
        }
        if (array_key_exists('is_blocked', $data)) {
            $user->is_blocked = $data['is_blocked'];
        }
        $user->save();

        return response()->json(['data' => $this->user($user->loadCount(['generations as created' => fn ($g) => $g->where('status', 'done')]))]);
    }

    /** Adds or takes tokens; the change is written to the user's history like any other operation. */
    public function balance(Request $request, User $user, Wallet $wallet)
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-100000000,100000000'],
            'comment' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $data['amount'] > 0
                ? $wallet->credit($user, $data['amount'], 'bonus', 'Начисление от администрации', [], $data['comment'] ?? null)
                : $wallet->debit($user, -$data['amount'], 'Списание администрацией', [], $data['comment'] ?? null);
        } catch (InsufficientBalance) {
            throw ValidationException::withMessages(['amount' => 'На балансе меньше токенов']);
        }

        return response()->json(['data' => $this->user($user->refresh()->loadCount(['generations as created' => fn ($g) => $g->where('status', 'done')]))]);
    }

    public function payments(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'succeeded', 'canceled'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $q = Payment::query()->with('user');
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        $this->period($q, $f);
        $page = $q->latest('id')->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(fn (Payment $p) => [
                'id' => $p->id, 'status' => $p->status, 'provider' => $p->provider, 'purpose' => $p->purpose, 'tokens' => $p->tokens,
                'plan' => $p->plan, 'period' => $p->period, 'amount' => (float) $p->amount, 'currency' => $p->currency,
                'user' => $p->user ? ['id' => $p->user->id, 'telegram_id' => $p->user->telegram_id, 'name' => $p->user->displayName(), 'username' => $p->user->username] : null,
                'created_at' => $p->created_at?->toIso8601String(),
            ]),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function stats(Request $request)
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $gens = Generation::query();
        $this->period($gens, $f);
        $users = User::query();
        $this->period($users, $f);
        $pays = Payment::query()->where('status', 'succeeded');
        $this->period($pays, $f);

        $since = now()->subDays(13)->startOfDay();
        $days = fn (Builder $q) => $q->where('created_at', '>=', $since)->selectRaw('date(created_at) as day, count(*) as n')->groupBy('day')->pluck('n', 'day');
        $perDay = ['generations' => $days(Generation::query()), 'users' => $days(User::query())];
        $series = [];
        for ($d = $since->copy(); $d <= now(); $d->addDay()) {
            $key = $d->toDateString();
            $series[] = ['day' => $key, 'generations' => (int) ($perDay['generations'][$key] ?? 0), 'users' => (int) ($perDay['users'][$key] ?? 0)];
        }

        return response()->json([
            'users' => ['total' => User::count(), 'new' => (clone $users)->count(), 'blocked' => User::where('is_blocked', true)->count(),
                'subscribed' => User::where('plan', '!=', 'free')->where('plan_expires_at', '>', now())->count()],
            'generations' => [
                'total' => (clone $gens)->count(),
                'active' => Generation::whereIn('status', ['queued', 'processing'])->count(),
                'tokens' => (int) (clone $gens)->where('status', 'done')->sum('cost'),
                'by_kind' => (clone $gens)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
                'by_status' => (clone $gens)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            ],
            'payments' => [
                'count' => (clone $pays)->count(),
                'by_currency' => (clone $pays)->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency')->map(fn ($v) => (float) $v),
                'tokens' => (int) (clone $pays)->sum('tokens'),
            ],
            'series' => $series,
        ]);
    }

    private function period(Builder $q, array $f): void
    {
        $tz = config('app.timezone');
        if (! empty($f['from'])) {
            $q->where('created_at', '>=', Carbon::parse($f['from'])->setTimezone($tz));
        }
        if (! empty($f['to'])) {
            $q->where('created_at', '<=', Carbon::parse($f['to'])->setTimezone($tz));
        }
    }

    private function user(User $u): array
    {
        return [
            'id' => $u->id, 'telegram_id' => $u->telegram_id, 'name' => $u->displayName(), 'username' => $u->username,
            'photo_url' => $u->photo_url, 'balance' => $u->balance, 'plan' => $u->activePlan(),
            'plan_expires_at' => $u->activePlan() === 'free' ? null : $u->plan_expires_at?->toIso8601String(),
            'is_blocked' => $u->is_blocked, 'is_admin' => AdminOnly::allows($u), 'created' => (int) ($u->created ?? 0),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
