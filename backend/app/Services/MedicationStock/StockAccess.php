<?php

namespace App\Services\MedicationStock;

use App\Exceptions\StockException;
use App\Models\User;
use App\Services\Auth\UserAccessContext;

class StockAccess
{
    public function authorize(User $user, int $facilityId, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $facilityId
                && in_array('stock.view', $entry['permissions'], true)
                && in_array('stock.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions'], 'today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new StockException('STOCK_ACCESS_DENIED', 'ليس لديك صلاحية لهذه العملية في المنشأة المحددة.', 403);
    }

    public function capabilities(array $facility): array
    {
        $permissions = $facility['permissions'];

        return [
            'manage' => in_array('stock.suppliers.manage', $permissions, true),
            'receive' => in_array('stock.receive', $permissions, true),
            'adjust' => in_array('stock.adjust', $permissions, true),
            'issue' => in_array('stock.issue', $permissions, true),
            'return' => in_array('stock.return', $permissions, true),
            'export' => in_array('stock.export', $permissions, true),
        ];
    }
}
