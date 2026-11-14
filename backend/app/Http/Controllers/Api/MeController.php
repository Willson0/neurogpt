<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Transaction;
use App\Models\UserProfile;
use App\Services\Billing\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    public function show(Request $request)
    {
        return (new UserResource($request->user()))->response()->setStatusCode(200);
    }

    public function transactions(Request $request)
    {
        $rows = Transaction::where('user_id', $request->user()->id)->latest('id')->paginate(50);

        return response()->json([
            'data' => $rows->map(fn ($t) => [
                'id' => $t->id, 'type' => $t->type, 'amount' => $t->amount, 'title' => $t->title,
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
