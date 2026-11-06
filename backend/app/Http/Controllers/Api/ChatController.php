<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalance;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Billing\Wallet;
use App\Services\Generation\ProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        return Chat::where('user_id', $request->user()->id)->latest('updated_at')->limit(20)
            ->get(['id', 'title', 'updated_at']);
    }

    public function show(Request $request, Chat $chat)
    {
        abort_unless($chat->user_id === $request->user()->id, 404);

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title,
            'messages' => $chat->messages()->get(['id', 'role', 'content', 'cost', 'created_at']),
        ]);
    }

    /** Sends one user message (a new chat when chat_id is empty) and returns the model's reply. */
    public function send(Request $request, Wallet $wallet, ProviderManager $providers)
    {
        $data = $request->validate([
            'chat_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:8000'],
            'model' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $model = AiModel::active()->where('kind', 'text')->where('slug', $data['model'] ?? 'auto')->firstOrFail();

        if (! $user->canUse($model)) {
            return response()->json(['message' => 'Model requires a subscription', 'code' => 'model_locked', 'plan' => $model->min_plan], 403);
        }

        $chat = ! empty($data['chat_id'])
            ? Chat::where('user_id', $user->id)->findOrFail($data['chat_id'])
            : Chat::create(['user_id' => $user->id, 'title' => Str::limit($data['message'], 40)]);

        try {
            $tx = $wallet->debit($user, $model->price, 'Ответ в чате', [], $model->name);
        } catch (InsufficientBalance $e) {
            return response()->json([
                'message' => 'Not enough tokens', 'code' => 'insufficient_balance',
                'required' => $e->required, 'balance' => $e->balance,
            ], 402);
        }

        $history = $chat->messages()->latest('id')->limit(20)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        $history[] = ['role' => 'user', 'content' => $data['message']];

        try {
            $answer = $providers->for($model)->chat($model, $history);
        } catch (Throwable $e) {
            report($e);
            $wallet->credit($user, $model->price, 'refund', 'Возврат', [], 'Модель перегружена');

            return response()->json(['message' => 'Model is unavailable', 'code' => 'provider_error', 'user' => new UserResource($user->refresh())], 502);
        }

        ChatMessage::create(['chat_id' => $chat->id, 'role' => 'user', 'content' => $data['message']]);
        $reply = ChatMessage::create(['chat_id' => $chat->id, 'role' => 'assistant', 'content' => $answer, 'cost' => $model->price]);
        $chat->touch();

        return response()->json([
            'chat_id' => $chat->id,
            'message' => ['id' => $reply->id, 'role' => 'assistant', 'content' => $answer, 'cost' => $model->price],
            'user' => new UserResource($user->refresh()),
        ]);
    }
}
