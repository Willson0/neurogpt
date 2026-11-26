<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InsufficientBalance;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnly;
use App\Models\Generation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Payments\SubscriptionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** People, their balances and payments, and the numbers of the dashboard. */
class UsersController extends Controller
{
    public static function search(Builder $q, string $term): void
    {
        $like = '%'.addcslashes(ltrim($term, '@'), '%_\\').'%';
        $q->where(function (Builder $w) use ($term, $like) {
            // users.id is the Telegram id; "#id" is kept for old links from the panel.
            if (ctype_digit($term)) {
                $w->where('users.id', $term);
            } elseif (preg_match('/^#(\d+)$/', $term, $m)) {
                $w->where('users.id', $m[1]);
            } else {
                $w->where('username', 'like', $like)
                    ->orWhereHas('profile', fn (Builder $p) => $p->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
            }
        });
    }

    public function index(Request $request)
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:64'], 'filter' => ['nullable', Rule::in(['paid', 'blocked'])]]);

        $q = User::query()->with('profile')->withCount(['generations as created' => fn ($g) => $g->where('status', 'done')]);
        if (trim((string) ($f['q'] ?? '')) !== '') {
            self::search($q, trim($f['q']));
        }
        if (($f['filter'] ?? null) === 'blocked') {
            $q->blocked();
        }
        if (($f['filter'] ?? null) === 'paid') {
            $q->subscribed();
        }
        $total = (clone $q)->count();
        $page = $q->latest('created_at')->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => $this->user($u)),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'summary' => ['total' => $total],
            'plans' => SubscriptionCatalog::names(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'is_blocked' => ['sometimes', 'boolean'],
            'plan' => ['sometimes', Rule::in(array_keys(SubscriptionCatalog::names()))],
            'plan_days' => [Rule::requiredIf($request->filled('plan') && $request->input('plan') !== 'free'), 'nullable', 'integer', 'between:1,3660'],
        ]);
        if (! empty($data['is_blocked']) && AdminOnly::allows($user)) {
            throw ValidationException::withMessages(['is_blocked' => 'Администратора заблокировать нельзя']);
        }
        if (isset($data['plan'])) {
            $user->setPlan($data['plan'], $data['plan'] === 'free' ? null : now()->addDays((int) $data['plan_days']));
            if ($data['plan'] === 'free') {
                $user->forceFill(['auto_continue' => false]);
            }
            $user->save();
        }
        if (array_key_exists('is_blocked', $data)) {
            $user->updateProfile(['is_blocked' => $data['is_blocked']]);
        }

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

    // ---------- admins ----------

    public function admins(Request $request)
    {
        return response()->json(['data' => $this->adminList(), 'can_manage' => AdminOnly::isOwner($request->user())]);
    }

    /** Only a main admin (hardcoded in config) may give or take admin rights. */
    public function addAdmin(Request $request)
    {
        abort_unless(AdminOnly::isOwner($request->user()), 403, 'Only the main admin can manage admins');
        $data = $request->validate(['user' => ['required', 'string', 'max:64']]);
        $term = ltrim(trim($data['user']), '@');

        if (ctype_digit($term) && strlen($term) <= 15 && (int) $term > 0) {
            $id = (int) $term;
        } else {
            $id = (int) User::where('username', $term)->value('id');
            if (! $id) {
                throw ValidationException::withMessages(['user' => 'Такого @username в приложении нет. Укажите числовой Telegram id.']);
            }
        }
        if (AdminOnly::allowsId($id)) {
            throw ValidationException::withMessages(['user' => 'Этот человек уже администратор']);
        }
        if (count(AdminOnly::added()) >= 50) {
            throw ValidationException::withMessages(['user' => 'Слишком много администраторов']);
        }
        $username = User::whereKey($id)->value('username') ?? '';
        if (! DB::table('admins')->insertOrIgnore(['id' => $id, 'username' => $username])) {
            throw ValidationException::withMessages(['user' => 'Этот человек уже администратор']);
        }

        return response()->json(['data' => $this->adminList()], 201);
    }

    public function removeAdmin(Request $request, int $telegramId)
    {
        abort_unless(AdminOnly::isOwner($request->user()), 403, 'Only the main admin can manage admins');
        abort_unless(in_array($telegramId, AdminOnly::added(), true), 404);
        DB::transaction(function () use ($telegramId) {
            DB::table('admins')->where('id', $telegramId)->delete();
            DB::table('wa_admin_generation_alerts')->where('telegram_id', $telegramId)->delete();
        });

        return response()->json(['data' => $this->adminList()]);
    }

    public function generationAlerts(Request $request, int $telegramId)
    {
        abort_unless(AdminOnly::isOwner($request->user()) || (int) $request->user()->telegram_id === $telegramId, 403);
        abort_unless(AdminOnly::allowsId($telegramId), 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        if ($data['enabled']) {
            DB::table('wa_admin_generation_alerts')->insertOrIgnore(['telegram_id' => $telegramId]);
        } else {
            DB::table('wa_admin_generation_alerts')->where('telegram_id', $telegramId)->delete();
        }

        return response()->json(['data' => $this->adminList()]);
    }

    private function adminList(): array
    {
        $ids = [...AdminOnly::owners(), ...AdminOnly::added()];
        $users = User::whereIn('id', $ids)->get()->keyBy('id');
        $botNames = DB::table('admins')->whereIn('id', $ids)->pluck('username', 'id');

        $alerts = DB::table('wa_admin_generation_alerts')->whereIn('telegram_id', $ids)->pluck('telegram_id')->map(fn ($id) => (int) $id)->all();

        return collect($ids)->unique()->values()->map(fn (int $id) => [
            'telegram_id' => $id,
            'name' => $users->get($id)?->displayName(),
            'username' => $users->get($id)?->username ?: ($botNames[$id] ?? null),
            'is_owner' => in_array($id, AdminOnly::owners(), true),
            'opened_app' => $users->has($id),
            'generation_alerts' => in_array($id, $alerts, true),
        ])->all();
    }

    public function stats(Request $request)
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $gens = Generation::query();
        $this->period($gens, $f);
        $users = User::query();
        $this->period($users, $f);
        $pays = Payment::overview()->where('status', 'succeeded');
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
            'users' => ['total' => User::count(), 'new' => (clone $users)->count(), 'blocked' => User::query()->blocked()->count(),
                'subscribed' => User::query()->subscribed()->count()],
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
            'is_blocked' => $u->is_blocked, 'is_admin' => AdminOnly::allows($u), 'is_owner' => AdminOnly::isOwner($u), 'created' => (int) ($u->created ?? 0),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
