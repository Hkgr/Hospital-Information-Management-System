<?php

namespace App\Services\Dossiers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierMedicalWriter
{
    public function __construct(private DossierWrites $writes) {}

    public function save(Request $r, array $f, int $id, array $input): int
    {
        return $this->writes->once($r, $f, $input, "medical:$id", function () use ($r, $f, $id, $input) {
            $old = $this->writes->dossier($f, $id);
            DossierWrites::version($old, $input['lock_version']);
            $prior = DB::table('dossier_oncology_selections')->where('dossier_id', $id)->orderBy('id')->get()->all();
            if ($old['is_oncology'] && ! $input['is_oncology'] && empty($input['confirm_hide_oncology'])) {
                throw ValidationException::withMessages(['confirm_hide_oncology' => 'أكد الاحتفاظ بالبيانات الورمية تاريخيًا وإخفاءها من العرض الحالي.']);
            }
            $fields = ['is_oncology' => $input['is_oncology'], 'disability_text' => $input['disability_text'] ?? null, 'clinical_history' => $input['clinical_history'] ?? null, 'weight_kg' => $input['weight_kg'] ?? null, 'height_cm' => $input['height_cm'] ?? null];
            if ($input['is_oncology']) {
                $fields += ['previous_examinations' => $input['previous_examinations'] ?? null, 'medication_source' => $input['medication_source'] ?? null, 'other_organization' => ($input['medication_source'] ?? null) === 'other_organization' ? $input['other_organization'] : null];
                foreach (['history', 'treatment'] as $group) {
                    // Omitted selections are preserved, while submitted arrays explicitly change them.
                    if (! array_key_exists($group, $input)) {
                        continue;
                    }
                    $q = DB::table('dossier_oncology_selections')->where('dossier_id', $id)->where('selection_group', $group);
                    foreach ((clone $q)->get() as $row) {
                        $active = in_array($row->code, $input[$group], true);
                        if ((bool) $row->is_active !== $active) {
                            DB::table('dossier_oncology_selections')->where('id', $row->id)->update(['is_active' => $active, 'lock_version' => $row->lock_version + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()]);
                        }
                    }
                    foreach ($input[$group] as $code) {
                        if (! (clone $q)->where('code', $code)->exists()) {
                            DB::table('dossier_oncology_selections')->insert(['dossier_id' => $id, 'facility_id' => $f['id'], 'selection_group' => $group, 'code' => $code, 'entered_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }
            }
            DB::table('patient_dossiers')->where('id', $id)->update($fields + ['lock_version' => $old['lock_version'] + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()]);
            $this->writes->progress($r, $f, $id, 'medical');
            $this->writes->audit($r, $f, 'dossier_medical', $id, Arr::only($old, array_keys($fields)) + ['selections' => $prior], $fields + ['selections' => DB::table('dossier_oncology_selections')->where('dossier_id', $id)->orderBy('id')->get()->all()]);

            return $id;
        });
    }
}
