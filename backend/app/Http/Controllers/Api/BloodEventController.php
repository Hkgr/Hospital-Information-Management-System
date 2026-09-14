<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BloodBankException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BloodBank\BloodEventQuery;
use App\Http\Requests\BloodBank\SaveBloodEvent;
use App\Services\BloodBank\BloodBankAccess;
use App\Services\BloodBank\BloodBankWriter;
use App\Services\BloodBank\BloodEventQueries;
use App\Services\BloodBank\BloodEventReports;
use App\Services\BloodBank\BloodEventWriter;
use App\Services\Catalog\CatalogQueries;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Blood bank events')]
class BloodEventController extends Controller
{
    public function __construct(private BloodBankAccess $access, private BloodEventQueries $queries, private BloodEventWriter $writer) {}

    public function retired(Request $r)
    {
        $this->access->facility($r->user(), $r->integer('facility_id'));
        throw new BloodBankException('BLOOD_BANK_LEGACY_WRITE_RETIRED', 'انتقل إلى سجل بنك الدم لتسجيل الشخص والواقعة معًا أو تعديل المرجع الموحد.', 410);
    }

    private function facility(Request $r, string $action = 'view'): array
    {
        $f = $this->access->facility($r->user(), $r->integer('facility_id'), $action);
        if (DB::table('blood_donors')->where('facility_id', $f['id'])->whereNull('person_id')->exists() || DB::table('blood_recipients')->where('facility_id', $f['id'])->whereNull('person_id')->exists()) {
            throw new BloodBankException('BLOOD_BANK_RECONCILIATION_REQUIRED', 'يلزم استكمال ربط بيانات بنك الدم القديمة بواسطة مسؤول النظام قبل التسجيل.', 409);
        }
        foreach (['blood_donations' => 'blood_donation_id', 'blood_transfusions' => 'blood_transfusion_id'] as $table => $column) {
            if (DB::table($table)->where('facility_id', $f['id'])->whereNotIn('id', DB::table('blood_bank_events')->whereNotNull($column)->select($column))->exists()) {
                throw new BloodBankException('BLOOD_BANK_RECONCILIATION_REQUIRED', 'يلزم استكمال ترحيل الوقائع التاريخية بواسطة مسؤول النظام.', 409);
            }
        }

        return $f;
    }

    public function index(BloodEventQuery $r)
    {
        $f = $this->facility($r);

        return response()->json($this->queries->listing($f['id'], $r->validated()));
    }

    public function people(BloodEventQuery $r)
    {
        $f = $this->facility($r);
        $p = $this->queries->people($f['id'], $r->input('search'))->orderBy('code')->paginate($r->integer('per_page', 20), ['id', 'code', 'name', 'patient_code', 'blood_group', 'rh', 'is_active'], 'page', $r->integer('page', 1));
        $p->setCollection($p->getCollection()->map(fn ($v) => (array) $v + ['name_ar' => $v->name]));

        return response()->json(['data' => $p->items(), 'meta' => CatalogQueries::meta($p)]);
    }

    public function person(BloodEventQuery $r, int $person)
    {
        return response()->json(['data' => $this->queries->person($person, $this->facility($r)['id'])]);
    }

    public function event(BloodEventQuery $r, int $event)
    {
        return response()->json(['data' => $this->queries->event($event, $this->facility($r)['id'])]);
    }

    public function store(SaveBloodEvent $r)
    {
        $f = $this->facility($r);
        $id = $this->writer->save($r, $f, $r->validated());

        return response()->json(['data' => $this->queries->event($id, $f['id'])], 201);
    }

    public function update(SaveBloodEvent $r, int $event)
    {
        $f = $this->facility($r);
        $id = $this->writer->save($r, $f, $r->validated(), $event);

        return response()->json(['data' => $this->queries->event($id, $f['id'])]);
    }

    public function updatePerson(Request $r, int $person)
    {
        $input = $r->validate(['facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:1'], 'person' => ['required', 'array']]);
        $f = $this->facility($r, 'update');
        $id = app(BloodBankWriter::class)->once($r, $f, $input, 'unified-person:'.$person, fn () => $this->writer->person($r, $f, $input['person'] + ['lock_version' => $input['lock_version']], $person));

        return response()->json(['data' => $this->queries->person($id, $f['id'])]);
    }

    public function legacy(BloodEventQuery $r, string $kind, int $item, ?int $donation = null)
    {
        $f = $this->facility($r);
        $p = DB::table($kind === 'donor' ? 'blood_donors' : 'blood_recipients')->where('id', $item)->where('facility_id', $f['id'])->value('person_id');
        if (! $p) {
            throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'الملف القديم غير متاح.', 404);
        }
        $event = $donation ? DB::table('blood_bank_events')->join('blood_donations as d', 'd.id', '=', 'blood_bank_events.blood_donation_id')->where('d.donor_id', $item)->where('d.id', $donation)->where('blood_bank_events.person_id', $p)->where('blood_bank_events.facility_id', $f['id'])->value('blood_bank_events.id') : null;
        if ($donation && ! $event) {
            throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'التبرع القديم غير متاح.', 404);
        }

        return response()->json(['data' => ['person_id' => $p, 'event_id' => $event]]);
    }

    public function export(BloodEventQuery $r, string $format)
    {
        return app(BloodEventReports::class)->export($r, $this->facility($r, 'export'), $r->validated(), $format);
    }

    public function legacyDonation(BloodEventQuery $r, int $item, int $donation)
    {
        return $this->legacy($r, 'donor', $item, $donation);
    }

    public function personReport(BloodEventQuery $r, int $person, string $format)
    {
        return app(BloodEventReports::class)->export($r, $this->facility($r, 'export'), [], $format, $person);
    }

    public function eventReport(BloodEventQuery $r, int $event, string $format)
    {
        return app(BloodEventReports::class)->export($r, $this->facility($r, 'export'), [], $format, null, $event);
    }
}
