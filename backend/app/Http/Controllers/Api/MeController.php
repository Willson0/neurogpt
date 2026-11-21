<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AiModel;
use App\Models\Transaction;
use App\Models\UserProfile;
use App\Services\Billing\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request)
    {
        return (new UserResource($request->user()))->response()->setStatusCode(200);
    }

    public function selectModel(Request $request)
    {
        $input = $request->validate([
            'kind' => ['required', Rule::in(['image', 'video'])],
            'model' => ['required', 'string', 'max:32'],
        ]);
        $model = AiModel::active()->where('kind', $input['kind'])->where('slug', $input['model'])
            ->whereNotNull('bot_model_id')->firstOrFail();
        $user = $request->user();
        if (! $user->canUse($model)) {
            return response()->json(['message' => 'Model requires a subscription', 'code' => 'model_locked'], 403);
        }
        $column = $input['kind'] === 'image' ? 'photo_model_id' : 'video_model_id';
        $user->forceFill([$column => $model->bot_model_id])->save();

        return response()->json(['model' => $model->slug]);
    }

    public function transactions(Request $request)
    {
        $rows = Transaction::history($request->user()->id)->paginate(50);

        return response()->json([
            'data' => $rows->map(fn ($t) => [
                'id' => $t->source === 'web' ? $t->id : $t->source.'-'.$t->id,
                'type' => $t->type, 'amount' => $t->amount, 'title' => $t->title,
                'details' => $t->details, 'created_at' => $t->created_at->toIso8601String(),
            ]),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ]);
    }

    public function dailyBonus(Request $request, Wallet $wallet)
    {
        $amount = (int) config('neurogpt.bonus.daily');

        $granted = DB::transaction(function () use ($request, $wallet, $amount) {
            // The profile row is the lock: two taps at once give one bonus.
            UserProfile::firstOrCreate(['user_id' => $request->user()->id]);
            $profile = UserProfile::whereKey($request->user()->id)->lockForUpdate()->first();
            if ($profile->last_bonus_on?->isToday()) {
                return false;
            }
            $continues = $profile->last_bonus_on?->isYesterday();
            $profile->update(['streak' => $continues ? $profile->streak + 1 : 1, 'last_bonus_on' => today()]);
            $user = $request->user();
            $user->setRelation('profile', $profile);
            $wallet->credit($user, $amount, 'bonus', 'Ежедневный бонус', [], 'Серия '.$user->streak.' дн.');
            $request->user()->refresh();

            return true;
        });

        return response()->json([
            'granted' => $granted,
            'amount' => $granted ? $amount : 0,
            'user' => new UserResource($request->user()->refresh()),
        ]);
    }
}
