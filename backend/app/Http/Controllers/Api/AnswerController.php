<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnly;
use App\Models\AiModel;
use App\Models\Bot\QueryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An AI answer from the bot table `query_logs`, for the answer page (/answer?id=<query_logs.id>) that the bot opens
 * from a button under its reply. The page renders the text as Markdown with LaTeX and code blocks.
 *
 * Answers are private: either the request is signed by Telegram (web_app button, the Mini App's initData) and the row
 * belongs to that person (admins may open any), or the link carries sig = the first 32 hex chars of
 * HMAC-SHA256("answer:<id>", <bot token>), which the bot can compute itself for a plain url button.
 */
class AnswerController extends Controller
{
    /** GET /api/answers/{id} with the Telegram Authorization header. */
    public function show(Request $request, int $id): JsonResponse
    {
        $log = QueryLog::find($id);
        $user = $request->user();
        abort_unless($log && ((int) $log->user_id === (int) $user->id || AdminOnly::allows($user)), 404, 'Answer not found');

        return $this->answer($log);
    }

    /** GET /api/answers/{id}/shared?sig=... without sign-in. */
    public function shared(Request $request, int $id): JsonResponse
    {
        $sig = (string) $request->query('sig', '');
        $key = (string) config('neurogpt.telegram.bot_token');
        abort_unless($key !== '' && $sig !== '' && hash_equals(self::signature($id), strtolower($sig)), 404, 'Answer not found');
        $log = QueryLog::find($id);
        abort_unless($log, 404, 'Answer not found');

        return $this->answer($log);
    }

    public static function signature(int $id): string
    {
        return substr(hash_hmac('sha256', "answer:$id", (string) config('neurogpt.telegram.bot_token')), 0, 32);
    }

    private function answer(QueryLog $log): JsonResponse
    {
        // query_logs.model is the model id the bot uses (models.name); people see models.text_name
        $model = AiModel::query()->where('name', $log->model)->orderBy('id')->first();

        return response()->json(['data' => [
            'id' => (int) $log->id,
            'model' => $model?->name ?: (string) $log->model,
            'type' => (string) $log->type,
            'prompt' => (string) $log->prompt,
            'answer' => (string) ($log->answer ?? ''),
            'created_at' => $log->created_at?->toIso8601String(),
        ]]);
    }
}
