<?php

namespace App\Jobs;

use App\Http\Middleware\AdminOnly;
use App\Services\Telegram\Bot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class NotifyGenerationFailure implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $generationId, public string $error) {}

    public function handle(Bot $bot): void
    {
        $recipients = DB::table('wa_admin_generation_alerts')
            ->whereIn('telegram_id', [...AdminOnly::owners(), ...AdminOnly::added()])->pluck('telegram_id');
        if ($recipients->isEmpty()) {
            return;
        }

        $event = DB::table('wa_provider_events')->where('generation_id', $this->generationId)
            ->where('outcome', 'error')->where('reason', '!=', 'unknown')->latest('id')->first();
        $code = in_array($this->error, ['failed', 'unavailable'], true) ? ($event?->reason ?? $this->error) : $this->error;
        $reason = match ($code) {
            'copyright' => 'Ограничение по авторским правам',
            'content_filtered', 'moderation' => 'Запрос отклонён фильтром',
            'timeout' => 'Истекло время ожидания',
            'connection' => 'Ошибка связи с провайдером',
            'credits' => 'Недостаточно средств у провайдера',
            'authorization' => 'Ошибка авторизации у провайдера',
            'rate_limit' => 'Достигнут лимит запросов',
            'size' => 'Файл превышает допустимый размер',
            'not_found' => 'Провайдер не нашёл задачу или файл',
            'input' => 'Провайдер отклонил параметры запроса',
            'result' => 'Провайдер вернул неполный результат',
            'upstream', 'unavailable' => 'Провайдер временно недоступен',
            default => 'Ошибка обработки. Подробности в карточке работы.',
        };
        $link = rtrim(config('app.url'), '/').'/admin?section=content&generation='.$this->generationId;
        foreach ($recipients as $id) {
            $bot->sendMessage((int) $id, "Ошибка генерации №{$this->generationId}\n{$reason}\n{$link}");
        }
    }
}
