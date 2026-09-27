<?php

namespace App\Http\Requests\Dossiers;

class RegisterReception extends SaveDossierSection
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['code' => ['prohibited'], 'smoking_status' => ['prohibited'], 'alcohol_status' => ['prohibited']]);
    }
}
