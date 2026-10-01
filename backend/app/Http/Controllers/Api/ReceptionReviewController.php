<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\GlobalAccess;
use App\Services\Reception\DuplicateReview;
use App\Services\Reception\IdentityCorrections;
use App\Services\Reception\ReviewAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceptionReviewController extends Controller
{
    public function identity(Request $r, int $dossier)
    {
        $f = app(ReviewAccess::class)->facility($r, 'reception.view');

        return response()->json(['data' => app(IdentityCorrections::class)->summary($r, $f, $dossier)]);
    }

    public function correct(Request $r, int $dossier)
    {
        $direct = $r->route('action') === 'correct';
        $f = app(ReviewAccess::class)->facility($r, $direct ? 'reception.correct' : 'reception.corrections.request');
        $id = app(IdentityCorrections::class)->submit($r, $f, $dossier, $direct);

        return response()->json(['data' => ['id' => $id, 'saved' => $direct ? 'corrected' : 'requested']], $direct ? 200 : 201);
    }

    public function corrections(Request $r)
    {
        $f = app(ReviewAccess::class)->facility($r, 'identity_corrections.review');

        return $this->listing('patient_identity_corrections', $f);
    }

    public function correction(Request $r, int $review)
    {
        $f = app(ReviewAccess::class)->facility($r, 'identity_corrections.review');

        return response()->json(['data' => app(IdentityCorrections::class)->detail($f, $review) + ['can_approve' => app(GlobalAccess::class)->allows($r->user(), 'patients.identity.review')]]);
    }

    public function correctionDecision(Request $r, int $review)
    {
        $f = app(ReviewAccess::class)->facility($r, 'identity_corrections.review');
        app(IdentityCorrections::class)->decide($r, $f, $review);

        return response()->json(['data' => app(IdentityCorrections::class)->detail($f, $review) + ['can_approve' => app(GlobalAccess::class)->allows($r->user(), 'patients.identity.review')]]);
    }

    public function patients(Request $r)
    {
        $f = app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');
        $input = $r->validate(['search' => 'required|string|min:3|max:100']);
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($input['search'])).'%';
        $rows = DB::table('patient_dossiers as d')->join('patients as p', 'p.id', '=', 'd.patient_id')->where('d.facility_id', $f['id'])->where('p.status', 'active')
            ->where(fn ($q) => $q->whereRaw("p.patient_code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("CONCAT_WS(' ', p.first_name, p.family_name) LIKE ? ESCAPE '!'", [$like]))
            ->orderBy('d.id')->limit(20)->get(['d.id', 'p.patient_code as code', 'p.first_name', 'p.family_name']);

        return response()->json(['data' => $rows]);
    }

    public function preview(Request $r)
    {
        $f = app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');
        $input = $r->validate(['canonical_dossier_id' => 'required|integer|min:1', 'duplicate_dossier_id' => 'required|integer|min:1']);
        $service = app(DuplicateReview::class);
        $data = $service->preview($f, $input['canonical_dossier_id'], $input['duplicate_dossier_id']);

        return response()->json(['data' => $data + ['preview_hash' => $service->hash($data)]]);
    }

    public function duplicates(Request $r)
    {
        $f = app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');

        return $this->listing('patient_duplicate_reviews', $f);
    }

    public function duplicate(Request $r, int $review)
    {
        $f = app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');

        return response()->json(['data' => app(DuplicateReview::class)->detail($f, $review) + ['can_approve' => app(GlobalAccess::class)->allows($r->user(), 'patients.duplicates.merge')]]);
    }

    public function duplicateRequest(Request $r)
    {
        $f = app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');
        $id = app(DuplicateReview::class)->request($r, $f);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function duplicateDecision(Request $r, int $review)
    {
        $f = app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');
        app(DuplicateReview::class)->decide($r, $f, $review);

        return response()->json(['data' => app(DuplicateReview::class)->detail($f, $review) + ['can_approve' => app(GlobalAccess::class)->allows($r->user(), 'patients.duplicates.merge')]]);
    }

    public function accounts(Request $r)
    {
        return response()->json(['error' => ['code' => 'RECEPTION_ACCOUNTS_RETIRED', 'message' => 'إدارة الحسابات من المستخدمين فقط؛ لم تُغيّر الحسابات أو تعييناتها.']], 410);
    }

    public function account(Request $r, int $user)
    {
        return $this->accounts($r);
    }

    private function listing(string $table, array $f)
    {
        $page = DB::table($table)->where('facility_id', $f['id'])->orderByDesc('id')->paginate(20);

        return response()->json(['data' => ['rows' => $page->items(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }
}
