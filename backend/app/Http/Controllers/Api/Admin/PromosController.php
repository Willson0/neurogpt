<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Payment;
use App\Services\Payments\Promos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PromosController
{
    public function index()
    {
        $stats = Payment::query()->whereNotNull('promo_id')->whereIn('status', ['pending', 'succeeded'])
            ->selectRaw('promo_source, promo_id, status, COUNT(*) AS total')
            ->groupBy('promo_source', 'promo_id', 'status')->get()
            ->keyBy(fn ($row) => "$row->promo_source:$row->promo_id:$row->status");
        $rows = collect(['percent', 'fixed'])->flatMap(fn ($type) => Promos::rows($type)->orderByDesc('id')->get()
            ->map(fn ($row) => [
                ...((array) $row), 'type' => $type,
                'web_paid' => (int) ($stats["$type:$row->id:succeeded"]->total ?? 0),
                'web_pending' => (int) ($stats["$type:$row->id:pending"]->total ?? 0),
            ]));

        return response()->json(['data' => $rows]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:percent,fixed'],
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'uses' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $type = $data['type'];
        unset($data['type']);
        $data['name'] = strtoupper($data['name']);
        $this->checkDiscount($type, $data['discount']);
        $id = Cache::lock('promo-create:'.$data['name'], 10)->block(3, function () use ($type, $data) {
            foreach (['percent', 'fixed'] as $source) {
                if (Promos::rows($source)->whereRaw('UPPER(name) = ?', [$data['name']])->exists()) {
                    throw ValidationException::withMessages(['name' => 'Такой промокод уже существует']);
                }
            }

            return Promos::rows($type)->insertGetId($data + [
                'created_at' => now(),
                $type === 'percent' ? 'modified_at' : 'updated_at' => now(),
            ]);
        });

        return response()->json(['data' => ['id' => $id, 'type' => $type] + $data], 201);
    }

    public function update(Request $request, string $type, int $id)
    {
        $data = $request->validate([
            'discount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'uses' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $this->checkDiscount($type, $data['discount']);
        $query = Promos::rows($type)->where('id', $id);
        abort_unless($query->exists(), 404);
        $query->update($data + [$type === 'percent' ? 'modified_at' : 'updated_at' => now()]);

        return response()->json(['data' => ['type' => $type, ...((array) $query->first())]]);
    }

    private function checkDiscount(string $type, int $discount): void
    {
        if ($type === 'percent' && $discount > 100) {
            throw ValidationException::withMessages(['discount' => 'Процент должен быть от 1 до 100']);
        }
    }
}
