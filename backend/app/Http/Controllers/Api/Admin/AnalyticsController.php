<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ChatMessage;
use App\Models\Event;
use App\Models\Generation;
use App\Models\Payment;
use App\Models\Photoset;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Full analytics for the admin dashboard: totals for the chosen period, a series by day for the charts
 * and top lists (buttons, screens, models, sets, tools). Bot tables (logs_table, query_logs) are only read.
 */
class AnalyticsController extends Controller
{
    /** The longest series the charts draw, in days. */
    private const MAX_DAYS = 180;

    public function show(Request $request)
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $tz = config('app.timezone');
        $to = ! empty($f['to']) ? Carbon::parse($f['to'])->setTimezone($tz) : now();
        $from = ! empty($f['from']) ? Carbon::parse($f['from'])->setTimezone($tz) : null;

        // "All time" still draws a chart: from the first generation, at most MAX_DAYS back.
        $chartFrom = $from?->copy() ?? Carbon::parse(Generation::min('created_at') ?? now()->subDays(29))->setTimezone($tz);
        $chartFrom = $chartFrom->startOfDay();
        if ($chartFrom->lt($to->copy()->subDays(self::MAX_DAYS - 1)->startOfDay())) {
            $chartFrom = $to->copy()->subDays(self::MAX_DAYS - 1)->startOfDay();
        }
        if ($chartFrom->gt($to->copy()->subDays(6)->startOfDay())) {
            $chartFrom = $to->copy()->subDays(6)->startOfDay(); // at least a week, so a chart is a chart
        }

        $in = function (Builder $q, string $col = 'created_at') use ($from, $to): Builder {
            if ($from) {
                $q->where($col, '>=', $from);
            }

            return $q->where($col, '<=', $to);
        };

        $gens = fn () => $in(Generation::query(), 'wa_generations.created_at');
        $done = fn () => $gens()->where('status', 'done');
        $pays = fn () => $in(Payment::query()->state('succeeded'), 'payments.created_at');
        $tx = fn () => $in(Transaction::query());
        $events = fn (string $type) => $in(Event::query()->where('type', $type));

        $spent = (int) -$tx()->where('type', 'spend')->sum('amount') - (int) $tx()->where('type', 'refund')->sum('amount');

        $active = $in(Event::query())->distinct()->count('user_id');
        $activeGen = $gens()->distinct()->count('user_id');

        return response()->json([
            'period' => ['from' => $from?->toIso8601String(), 'to' => $to->toIso8601String(), 'chart_from' => $chartFrom->toDateString()],
            'users' => [
                'total' => User::count(),
                'new' => $in(User::query())->count(),
                'active' => max($active, $activeGen),
                'creators' => $activeGen,
                'subscribed' => User::query()->subscribed()->count(),
                'blocked' => User::query()->blocked()->count(),
            ],
            'generations' => [
                'total' => $gens()->count(),
                'done' => $done()->count(),
                'failed' => $gens()->where('status', 'failed')->count(),
                'active' => Generation::whereIn('status', ['queued', 'processing'])->count(),
                'tokens' => (int) $done()->sum('cost'),
                'avg_seconds' => $this->avgSeconds($done()),
                // Sets are counted apart from plain images and videos: "set_image" (photo sets) and "set_video" (video sets).
                'by_kind' => $gens()->selectRaw("kind, case when photoset_id is null then 0 else 1 end as is_set, count(*) as n, sum(case when status = 'done' then cost else 0 end) as tokens")
                    ->groupBy('kind', 'is_set')->orderByDesc('n')->get()
                    ->map(fn ($r) => ['key' => ((int) $r->is_set ? 'set_' : '').$r->kind, 'n' => (int) $r->n, 'tokens' => (int) $r->tokens]),
                'by_status' => $gens()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n),
                'models' => $this->top($gens()->whereNotNull('wa_generations.ai_model_id')
                    ->whereNull('wa_generations.photoset_id')->where('wa_generations.kind', '!=', 'tool')
                    ->selectRaw('wa_generations.ai_model_id as id, wa_generations.kind as kind, count(*) as n, sum(case when wa_generations.status = \'done\' then wa_generations.cost else 0 end) as tokens')
                    ->groupBy('wa_generations.ai_model_id', 'wa_generations.kind'), fn ($r) => $this->modelName((int) $r->id)),
                'sets' => $this->top($gens()->whereNotNull('wa_generations.photoset_id')
                    ->selectRaw('wa_generations.photoset_id as id, case when wa_generations.kind = \'video\' then \'video\' else \'photo\' end as kind, count(*) as n, sum(case when wa_generations.status = \'done\' then wa_generations.cost else 0 end) as tokens')
                    ->groupBy('wa_generations.photoset_id', 'wa_generations.kind'), fn ($r) => $this->setName((int) $r->id)),
                'tools' => $this->top($gens()->where('kind', 'tool')
                    ->selectRaw('tool as id, count(*) as n, sum(case when status = \'done\' then cost else 0 end) as tokens')
                    ->groupBy('tool'), fn ($r) => (string) (config("neurogpt.tools.{$r->id}.name") ?? $r->id)),
            ],
            'chat' => [
                'messages' => $in(ChatMessage::query())->where('role', 'user')->count(),
                'tokens' => (int) $in(ChatMessage::query())->sum('cost'),
            ],
            'tokens' => [
                'spent' => max(0, $spent),
                'sold' => (int) $tx()->whereIn('type', ['topup', 'subscription'])->sum('amount'),
                'bonus' => (int) $tx()->whereIn('type', ['bonus', 'referral'])->where('amount', '>', 0)->sum('amount'),
                'refunded' => (int) $tx()->where('type', 'refund')->sum('amount'),
            ],
            'payments' => [
                'count' => $pays()->count(),
                'payers' => $pays()->distinct()->count('user_id'),
                'rub' => round((float) $pays()->sum('payments.rub_total'), 2),
                'by_currency' => $pays()->selectRaw('total_valut as currency, sum(total) as total')->groupBy('total_valut')->pluck('total', 'currency')->map(fn ($v) => (float) $v),
                'by_purpose' => $pays()->join('shop', 'shop.id', '=', 'payments.item_id')
                    ->selectRaw("case when shop.category = ? then 'plan' else 'topup' end as purpose, count(*) as n", [config('neurogpt.bot.shop.sub_category')])
                    ->groupBy('purpose')->pluck('n', 'purpose')->map(fn ($n) => (int) $n),
                'tokens' => (int) $pays()->join('shop', 'shop.id', '=', 'payments.item_id')->where('shop.category', config('neurogpt.bot.shop.tokens_category'))->sum('shop.amount'),
                'pending' => $in(Payment::query()->state('pending'), 'payments.created_at')->count(),
            ],
            'clicks' => [
                'total' => $events('click')->count(),
                'top' => $events('click')->selectRaw('name, screen, count(*) as n, count(distinct user_id) as users')
                    ->groupBy('name', 'screen')->orderByDesc('n')->limit(60)->get()
                    ->map(fn ($r) => ['name' => $r->name, 'screen' => $r->screen, 'n' => (int) $r->n, 'users' => (int) $r->users]),
                'screens' => $events('view')->selectRaw('name, count(*) as n, count(distinct user_id) as users')
                    ->groupBy('name')->orderByDesc('n')->limit(30)->get()
                    ->map(fn ($r) => ['name' => $r->name, 'n' => (int) $r->n, 'users' => (int) $r->users]),
            ],
            'bot' => $this->bot($from, $to),
            'series' => $this->series($chartFrom, $to),
        ]);
    }

    /** Average seconds from start to finish of finished jobs; computed in PHP to stay database-neutral. */
    private function avgSeconds(Builder $q): ?int
    {
        $rows = (clone $q)->whereNotNull('started_at')->whereNotNull('finished_at')->latest('id')->limit(500)->get(['started_at', 'finished_at']);
        if ($rows->isEmpty()) {
            return null;
        }

        return (int) round($rows->avg(fn ($g) => max(0, $g->finished_at->getTimestamp() - $g->started_at->getTimestamp())));
    }

    private function top(Builder $q, callable $name, int $limit = 12)
    {
        return $q->orderByDesc('n')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $name($r), 'kind' => $r->kind ?? null, 'n' => (int) $r->n, 'tokens' => (int) $r->tokens]);
    }

    private function modelName(int $id): string
    {
        return (string) (AiModel::find($id)?->name ?? "#$id");
    }

    private function setName(int $id): string
    {
        return (string) (Photoset::find($id)?->name ?? "#$id");
    }

    /** One row per day: generations, tokens, new and active people, revenue, button presses. */
    private function series(Carbon $from, Carbon $to): array
    {
        $day = fn (Builder $q, string $agg = 'count(*)') => $q->where('created_at', '>=', $from)->where('created_at', '<=', $to)
            ->selectRaw("date(created_at) as day, $agg as v")->groupBy('day')->pluck('v', 'day');

        $data = [
            'generations' => $day(Generation::query()),
            'done' => $day(Generation::query()->where('status', 'done')),
            'tokens' => $day(Generation::query()->where('status', 'done'), 'sum(cost)'),
            'users' => $day(User::query()),
            'active' => $day(Event::query(), 'count(distinct user_id)'),
            'creators' => $day(Generation::query(), 'count(distinct user_id)'),
            'clicks' => $day(Event::query()->where('type', 'click')),
            'payments' => $day(Payment::query()->state('succeeded')),
            'rub' => $day(Payment::query()->state('succeeded'), 'sum(rub_total)'),
            'chat' => $day(ChatMessage::query()->where('role', 'user')),
        ];

        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $row = ['day' => $key];
            foreach ($data as $name => $values) {
                $row[$name] = $name === 'rub' ? round((float) ($values[$key] ?? 0), 2) : (int) ($values[$key] ?? 0);
            }
            $row['active'] = max($row['active'], $row['creators']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * What happens in the bot itself, read from its tables: actions it logs (logs_table: button presses and commands)
     * and requests (query_logs, where the app also writes its own requests). Never fails the dashboard.
     */
    private function bot(?Carbon $from, Carbon $to): array
    {
        $out = ['actions' => [], 'events' => [], 'queries' => [], 'queries_total' => 0, 'tokens' => 0];
        $range = function ($q) use ($from, $to) {
            if ($from) {
                $q->where('created_at', '>=', $from);
            }

            return $q->where('created_at', '<=', $to);
        };
        try {
            if (Schema::hasTable('logs_table')) {
                $out['actions'] = $range(DB::table('logs_table'))->selectRaw('action as name, count(*) as n, count(distinct user_id) as users')
                    ->groupBy('action')->orderByDesc('n')->limit(40)->get()
                    ->map(fn ($r) => ['name' => (string) $r->name, 'n' => (int) $r->n, 'users' => (int) $r->users]);
                $out['events'] = $range(DB::table('logs_table'))->whereNotNull('event_type')->selectRaw('event_type as name, count(*) as n')
                    ->groupBy('event_type')->orderByDesc('n')->limit(20)->get()
                    ->map(fn ($r) => ['name' => (string) $r->name, 'n' => (int) $r->n]);
            }
            if (Schema::hasTable('query_logs')) {
                $out['queries'] = $range(DB::table('query_logs'))->selectRaw('type as name, count(*) as n, sum(token_used) as tokens')
                    ->groupBy('type')->orderByDesc('n')->limit(20)->get()
                    ->map(fn ($r) => ['name' => (string) $r->name, 'n' => (int) $r->n, 'tokens' => (int) $r->tokens]);
                $out['queries_total'] = (int) collect($out['queries'])->sum('n');
                $out['tokens'] = (int) collect($out['queries'])->sum('tokens');
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $out;
    }
}
