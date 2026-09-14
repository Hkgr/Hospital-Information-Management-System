<?php

namespace App\Services\Dossiers;

use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Http\Exceptions\HttpResponseException;

class DossierAccess
{
    public function facility(User $user, int $id): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('dossiers.view', $entry['permissions'], true)) {
                return $entry['facility'] + ['today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_ACCESS_DENIED', 'message' => 'لا يتوفر لك وصول إلى إضبارات هذه المنشأة.']], 403));
    }
}
