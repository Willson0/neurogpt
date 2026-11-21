<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Middleware\AdminOnly;
use App\Services\Admin\Settings;
use App\Services\Payments\RenewSubscriptions;
use App\Services\Payments\SubscriptionCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RenewalController
{
    public function show(Request $request)
    {
        return response()->json([
            'data' => config('neurogpt.renewal'),
            'server_enabled' => (bool) config('neurogpt.yookassa.auto_renewal'),
            'can_manage' => AdminOnly::isOwner($request->user()),
            'shop_terms' => SubscriptionCatalog::purchasable()->orderByDesc('amount')->pluck('amount')->unique()->values(),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(AdminOnly::isOwner($request->user()), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'steps' => ['required', 'array', 'list', 'min:1', 'max:8'],
            'steps.*' => ['required', 'array:days,wait_hours'],
            'steps.*.days' => ['required', 'integer', 'between:1,3660'],
            'steps.*.wait_hours' => ['required', 'integer', 'between:0,'.RenewSubscriptions::MAX_WAIT_HOURS],
        ]);

        $steps = array_map(fn (array $step) => array_map('intval', $step), $data['steps']);
        foreach ($steps as $index => $step) {
            if ($index > 0 && $step['days'] >= $steps[$index - 1]['days']) {
                throw ValidationException::withMessages(["steps.$index.days" => 'Каждый следующий срок должен быть короче предыдущего']);
            }
            if ($index > 0 && $step['wait_hours'] < 1) {
                throw ValidationException::withMessages(["steps.$index.wait_hours" => 'Между попытками должен пройти хотя бы час']);
            }
        }
        if (array_sum(array_column($steps, 'wait_hours')) > RenewSubscriptions::MAX_WAIT_HOURS) {
            throw ValidationException::withMessages(['steps' => 'Вся серия списаний должна укладываться в 90 дней']);
        }

        Settings::put('renewal', ['enabled' => (bool) $data['enabled'], 'steps' => $steps]);

        return $this->show($request);
    }
}
