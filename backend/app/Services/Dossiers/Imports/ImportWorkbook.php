<?php

namespace App\Services\Dossiers\Imports;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use ZipArchive;

class ImportWorkbook
{
    public const VERSION = 'patient-import-3';

    public const MAX_BYTES = 10485760;

    public const MAX_ROWS = 30000;

    public const MAX_PATIENTS = 5000;

    public const SHEETS = [
        'Patients' => ['source_record_id', 'local_patient_ref', 'patient_code', 'legacy_code', 'opening_date', 'first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'governorate_id', 'city_id', 'address_line', 'displacement_status', 'permanent_address', 'marital_status', 'occupation', 'smoking_status', 'alcohol_status', 'paper_file_number', 'is_oncology', 'disability_text', 'clinical_history', 'previous_examinations', 'medication_source', 'other_organization', 'import_note'],
        'Visits' => ['source_record_id', 'local_patient_ref', 'local_visit_ref', 'visit_date', 'is_referred', 'referring_hospital', 'referral_date', 'referral_reason', 'import_note'],
        'Diagnoses' => ['source_record_id', 'local_visit_ref', 'diagnosis_id', 'diagnosed_on', 'clinic_id', 'diagnosing_staff_id'],
        'Services' => ['source_record_id', 'local_visit_ref', 'catalog_id', 'clinic_id', 'doctor_id', 'note'],
        'Procedures' => ['source_record_id', 'local_visit_ref', 'catalog_id', 'clinic_id', 'doctor_id', 'note'],
        'Prescriptions' => ['source_record_id', 'local_visit_ref', 'prescribing_clinic_id', 'prescribing_staff_id', 'prescribed_on', 'note'],
        // Medication items belong to the visit's one explicit prescription, never dispensing.
        'Medications' => ['source_record_id', 'local_visit_ref', 'medication_id', 'display_order', 'note'],
        'Outcomes' => ['source_record_id', 'local_visit_ref', 'code', 'clinic_id', 'doctor_id', 'outcome_on', 'referral_target', 'outgoing_referral_date', 'outgoing_referral_reason', 'note'],
    ];

    private const LABELS = ['is_oncology' => 'ملف ورمي؟ 0 أو 1', 'disability_text' => 'معلومات الإعاقة', 'clinical_history' => 'قصة مرضية موثقة', 'previous_examinations' => 'الفحوص السابقة — وصف فقط', 'medication_source' => 'مصدر الدواء', 'other_organization' => 'اسم الجهة الأخرى', 'source_record_id' => 'معرّف المصدر الثابت *', 'local_patient_ref' => 'مرجع المريض داخل الملف *', 'local_visit_ref' => 'مرجع الزيارة داخل الملف *', 'patient_code' => 'كود مريض موجود فقط', 'legacy_code' => 'كود تاريخي / اسم بديل', 'opening_date' => 'بداية الملف الطبي الفعلية *', 'first_name' => 'الاسم الأول', 'family_name' => 'العائلة', 'father_name' => 'اسم الأب', 'mother_name' => 'اسم الأم', 'birth_date' => 'الميلاد حسب الدقة', 'birth_date_accuracy' => 'دقة الميلاد', 'gender' => 'الجنس', 'phone' => 'الهاتف', 'alt_phone' => 'هاتف بديل', 'governorate_id' => 'معرّف المحافظة', 'city_id' => 'معرّف المدينة', 'address_line' => 'عنوان السكن', 'displacement_status' => 'حالة النزوح', 'permanent_address' => 'عنوان الإقامة الدائم عند النزوح', 'marital_status' => 'الوضع العائلي', 'occupation' => 'المهنة', 'smoking_status' => 'التدخين', 'alcohol_status' => 'الكحول', 'paper_file_number' => 'رقم الملف الورقي', 'import_note' => 'ملاحظة مصدر غير سريرية', 'visit_date' => 'تاريخ الزيارة الفعلية *', 'is_referred' => 'محول؟ 0 أو 1 *', 'referring_hospital' => 'المشفى المحول', 'referral_date' => 'تاريخ التحويل', 'referral_reason' => 'سبب التحويل', 'diagnosis_id' => 'معرّف التشخيص *', 'diagnosed_on' => 'تاريخ التشخيص إن عُرف', 'clinic_id' => 'معرّف العيادة *', 'diagnosing_staff_id' => 'معرّف الطبيب المشخص *', 'catalog_id' => 'معرّف عنصر الدليل *', 'doctor_id' => 'معرّف الطبيب *', 'note' => 'ملاحظة موثقة', 'prescribing_clinic_id' => 'معرّف عيادة الوصفة *', 'prescribing_staff_id' => 'معرّف طبيب الوصفة *', 'prescribed_on' => 'تاريخ الوصفة الفعلي *', 'medication_id' => 'معرّف الدواء *', 'display_order' => 'الترتيب *', 'code' => 'كود المآل *', 'outcome_on' => 'تاريخ المآل *', 'referral_target' => 'جهة الإحالة', 'outgoing_referral_date' => 'تاريخ الإحالة', 'outgoing_referral_reason' => 'سبب الإحالة'];

    public static function isDate(string $key): bool
    {
        return str_ends_with($key, '_date') || str_ends_with($key, '_on');
    }

    public function template(array $facility, string $purpose, string $cutover, array $references): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getDefaultStyle()->getFont()->setName('Cairo')->setSize(11);
        $instructions = $book->createSheet()->setTitle('Instructions');
        $meta = ['template_version' => self::VERSION, 'facility_id' => (string) $facility['id'], 'purpose' => $purpose, 'cutover_date' => $cutover, 'exported_at' => now()->toIso8601String(),
            'تعليمات' => 'هذا قالب محدد الإصدار، وليس مستندًا طبيًا معتمدًا. لا تغيّر أسماء الأوراق أو المفاتيح المخفية. استخدم معرّفات دليل المنشأة فقط.',
            'الهوية' => 'كود المريض الموجود للربط فقط؛ المريض الجديد يحصل على كود نظامي. اترك الكود فارغًا للجديد. الأسماء وحدها لا تكفي للدمج.',
            'المراجع' => 'اكتب مراجع محلية ومعرّفات مصدر ثابتة لا تتغير عند إعادة رفع الملف. كل سطر فرعي يشير إلى local_visit_ref، وليس اسم المريض.',
            'الزيارات' => 'وجود بطاقة لا يعني حدوث زيارة. أضف الزيارات الواقعة صراحة فقط. لا تعني الحقول الممتلئة اكتمال الزيارة أو تفعيل البطاقة.',
            'الميلاد' => 'exact / year_only / estimated / unknown. لا تحوّل السنة وحدها إلى تاريخ دقيق. اكتب السنة نصًا عند year_only.',
            'القيم' => 'الجنس: male / female / unknown. النزوح: resident / idp / unknown. الوضع العائلي: single / married / divorced / widowed / unknown. التدخين والكحول: yes / no / former / unknown. عنوان الإقامة الدائم يُحفظ عند النزوح فقط. التواريخ الأخرى تواريخ Excel أو YYYY-MM-DD.',
            'الأدوية' => 'Medications عناصر الوصفة المسجلة في Prescriptions؛ ليست صرف أدوية ولا جلسة علاج أورام.',
            'خارج النطاق' => 'لا تستورد التشريح المرضي أو خطط وجلسات وجرعات الأورام أو الدم أو المرفقات. تُسجل لاحقًا في مساراتها المعتمدة.',
            'الحدود' => '5000 مريض، 30000 سطر إجمالي، 10 MiB ملف مضغوط. لا صيغ ولا وحدات ماكرو ولا روابط خارجية.'];
        foreach ($meta as $key => $value) {
            $n = ($n ?? 0) + 1;
            $instructions->setCellValueExplicit('A'.$n, $key, DataType::TYPE_STRING);
            $instructions->setCellValueExplicit('B'.$n, $value, DataType::TYPE_STRING);
        }
        $instructions->getColumnDimension('A')->setWidth(24);
        $instructions->getColumnDimension('B')->setWidth(95);
        $instructions->getStyle('A1:B'.$n)->getAlignment()->setWrapText(true);
        $instructions->getDefaultRowDimension()->setRowHeight(55);
        $instructions->getProtection()->setSheet(true);
        foreach (self::SHEETS as $name => $keys) {
            $s = $book->createSheet()->setTitle($name);
            foreach ($keys as $i => $key) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $s->setCellValueExplicit($col.'1', $key, DataType::TYPE_STRING);
                $s->setCellValueExplicit($col.'2', self::LABELS[$key], DataType::TYPE_STRING);
                $s->getColumnDimension($col)->setWidth(str_contains($key, 'note') ? 38 : 24);
                $numeric = str_ends_with($key, '_id') && $key !== 'source_record_id' || in_array($key, ['is_referred', 'display_order']);
                $s->getStyle($col.':'.$col)->getNumberFormat()->setFormatCode(self::isDate($key) && $key !== 'birth_date' ? 'yyyy-mm-dd' : ($numeric ? '0' : '@'));
                if (in_array($key, ['birth_date_accuracy', 'gender', 'displacement_status', 'marital_status', 'smoking_status', 'alcohol_status', 'is_referred'])) {
                    $choices = match ($key) {
                        'birth_date_accuracy' => 'exact,year_only,estimated,unknown', 'gender' => 'male,female,unknown', 'displacement_status' => 'resident,idp,unknown',
                        'marital_status' => 'single,married,divorced,widowed,unknown', 'smoking_status', 'alcohol_status' => 'yes,no,former,unknown', default => '0,1'
                    };
                    $v = $s->getCell($col.'3')->getDataValidation();
                    $v->setType(DataValidation::TYPE_LIST)->setFormula1('"'.$choices.'"')->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setSqref($col.'3:'.$col.'5002');
                }
            }
            $end = Coordinate::stringFromColumnIndex(count($keys));
            $s->getRowDimension(1)->setVisible(false);
            $s->getRowDimension(2)->setRowHeight(36);
            $s->getStyle('A2:'.$end.'2')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $s->getStyle('A2:'.$end.'2')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF215B52');
            $s->freezePane('C3')->setAutoFilter('A2:'.$end.'5002');
            $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(2, 2)->setPrintArea('A2:'.$end.'3');
        }
        $ref = $book->createSheet()->setTitle('Reference_Data');
        $ref->fromArray([['نوع الدليل', 'المعرّف', 'الكود', 'الاسم', 'سياق / تاريخ الارتباط']]);
        foreach ($references as $i => $row) {
            foreach (array_values($row) as $j => $value) {
                $ref->setCellValueExplicit([$j + 1, $i + 2], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $ranges = [];
        foreach ($references as $i => $row) {
            $kind = $row[0];
            $ranges[$kind] = [$ranges[$kind][0] ?? $i + 2, $i + 2];
        }
        foreach ($ranges as $kind => [$first, $last]) {
            $book->addNamedRange(new NamedRange('ref_'.$kind, $ref, '$B$'.$first.':$B$'.$last));
        }
        foreach (self::SHEETS as $name => $keys) {
            foreach ($keys as $i => $key) {
                $kind = match ($key) {
                    'diagnosis_id' => 'diagnoses', 'medication_id' => 'medications',
                    'clinic_id', 'prescribing_clinic_id' => 'clinics', 'doctor_id', 'diagnosing_staff_id', 'prescribing_staff_id' => 'doctors',
                    'governorate_id' => 'governorates', 'city_id' => 'cities', 'catalog_id' => strtolower($name), default => null,
                };
                if ($kind && isset($ranges[$kind])) {
                    $col = Coordinate::stringFromColumnIndex($i + 1);
                    $book->getSheetByName($name)->getCell($col.'3')->getDataValidation()->setType(DataValidation::TYPE_LIST)->setFormula1('ref_'.$kind)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setSqref($col.'3:'.$col.'5002');
                }
            }
        }
        foreach (['A' => 24, 'B' => 16, 'C' => 25, 'D' => 48, 'E' => 65] as $col => $width) {
            $ref->getColumnDimension($col)->setWidth($width);
        }
        $ref->freezePane('A2')->setAutoFilter('A1:E'.max(1, count($references) + 1));
        $ref->getProtection()->setSheet(true);
        foreach ($book->getAllSheets() as $s) {
            $s->setRightToLeft(true);
            $s->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
            $s->getPageMargins()->setLeft(.3)->setRight(.3)->setTop(.5)->setBottom(.5);
            $s->getHeaderFooter()->setOddFooter('&Rصفحة &P / &N');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function read(string $path, int $facility, string $purpose, string $cutover): array
    {
        $this->inspectZip($path);
        $errors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $book = null;
        try {
            $book = new ImportXmlRows($path);
            $allowed = ['Instructions', ...array_keys(self::SHEETS), 'Reference_Data'];
            if (array_keys($book->sheets) !== $allowed) {
                $this->invalid('القالب قديم أو غير معتمد. نزّل قالب الاستيراد الحالي وانقل الحقول المدعومة فقط؛ لا يمكن رفع نموذج الإضبارات القديم مباشرة.');
            }
            $meta = iterator_to_array($book->rows('Instructions'));
            foreach ([1 => ['template_version', self::VERSION], 2 => ['facility_id', (string) $facility], 3 => ['purpose', $purpose], 4 => ['cutover_date', $cutover]] as $row => [$key, $value]) {
                if (($meta[$row][1] ?? null) !== $key || (string) ($meta[$row][2] ?? '') !== $value) {
                    $this->invalid('إصدار القالب أو المنشأة أو غرض الدفعة أو تاريخ الانتقال لا يطابق الطلب.');
                }
            }
            $rows = [];
            $sources = [];
            $seenSources = [];
            foreach (self::SHEETS as $name => $keys) {
                $header = false;
                foreach ($book->rows($name) as $n => $values) {
                    if ($n === 1) {
                        if (array_values($values) !== $keys) {
                            $this->invalid('تغيّرت مفاتيح أعمدة القالب.');
                        }
                        $header = true;

                        continue;
                    }
                    if ($n === 2) {
                        continue;
                    }
                    if ($values && max(array_keys($values)) > count($keys)) {
                        $this->invalid('يتضمن الصف أعمدة خارج عقد القالب.');
                    }
                    $data = [];
                    foreach ($keys as $i => $key) {
                        $v = $values[$i + 1] ?? null;
                        if (self::isDate($key) && (is_int($v) || is_float($v))) {
                            $accuracy = $name === 'Patients' ? ($values[array_search('birth_date_accuracy', $keys, true) + 1] ?? null) : null;
                            if ($key === 'birth_date' && $accuracy === 'year_only' && $v >= 1000 && $v <= 9999) {
                                $v = (string) $v;
                            } else {
                                if ($v < 1 || $v > 2958465 || floor($v) !== (float) $v) {
                                    $this->invalid('تاريخ Excel غير صالح أو يتضمن وقتًا غير مدعوم.');
                                }
                                $calendar = Date::getExcelCalendar();
                                Date::setExcelCalendar($book->calendar1904 ? Date::CALENDAR_MAC_1904 : Date::CALENDAR_WINDOWS_1900);
                                try {
                                    $v = Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
                                } finally {
                                    Date::setExcelCalendar($calendar);
                                }
                            }
                        }
                        $data[$key] = $v === '' ? null : $v;
                    }
                    if (! array_filter($data, fn ($v) => $v !== null)) {
                        continue;
                    }
                    $source = $data['source_record_id'];
                    if (! is_string($source) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/', $source) || isset($seenSources[$source])) {
                        $this->invalid('يلزم معرّف مصدر نصي ثابت وفريد في المصنف كله، بأحرف لاتينية وأرقام و . _ : - فقط.');
                    }
                    $sources[$name][$source] = true;
                    $seenSources[$source] = true;
                    $rows[] = ['sheet' => $name, 'row_number' => $n, 'source_record_id' => $source, 'data' => $data];
                    if (count($rows) > self::MAX_ROWS || count($sources['Patients'] ?? []) > self::MAX_PATIENTS) {
                        $this->invalid('تجاوز الملف حد المرضى أو الصفوف؛ قسّمه إلى دفعات.');
                    }
                }
                if (! $header) {
                    $this->invalid('مفاتيح أعمدة القالب مفقودة.');
                }
            }
            if (libxml_get_errors()) {
                $this->invalid('ملف XML داخل XLSX غير مكتمل أو تالف.');
            }
            if (! ($sources['Patients'] ?? [])) {
                $this->invalid('الملف لا يحتوي مرضى للاستيراد.');
            }

            return $rows;
        } finally {
            $book?->close();
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
    }

    private function inspectZip(string $path): void
    {
        if (filesize($path) > self::MAX_BYTES) {
            abort(413);
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('الملف تالف أو محمي بكلمة مرور؛ يُقبل XLSX غير مشفر فقط.');
        }
        try {
            $total = 0;
            $names = [];
            if ($zip->numFiles > 500) {
                $this->invalid('حزمة Excel تتجاوز الحدود الآمنة.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $s = $zip->statIndex($i);
                if (isset($names[strtolower($s['name'])]) || str_contains($s['name'], '\\') || str_starts_with($s['name'], '/')) {
                    $this->invalid('حزمة Excel تحتوي أسماء ملفات غير آمنة أو مكررة.');
                }
                $names[strtolower($s['name'])] = true;
                $total += $s['size'];
                if ($s['size'] > 40000000 || $total > 80000000 || $s['size'] / max(1, $s['comp_size']) > 500 || ($s['encryption_method'] ?? 0) !== 0 || preg_match('~(\.\.|vbaProject|externalLinks|embeddings|activeX)~i', $s['name'])) {
                    $this->invalid('حزمة Excel غير آمنة أو تتجاوز الحدود؛ لا يُقبل ماكرو أو مرفقات أو روابط خارجية.');
                }
                if ((str_ends_with($s['name'], '.xml') || str_ends_with($s['name'], '.rels')) && preg_match('/<!DOCTYPE|<!ENTITY/i', $zip->getFromIndex($i))) {
                    $this->invalid('تعريفات XML الخارجية غير مسموحة.');
                }
                if (str_ends_with($s['name'], '.rels') && preg_match('/TargetMode\s*=\s*["\x27]External/i', $zip->getFromIndex($i))) {
                    $this->invalid('الروابط الخارجية غير مسموحة.');
                }
                if (str_starts_with($s['name'], 'xl/worksheets/') && preg_match('/<(?:[A-Za-z0-9_]+:)?f(?:\s|>)/', $zip->getFromIndex($i))) {
                    $this->invalid('لا تُقبل الصيغ في ملف الاستيراد، بما فيه أوراق التعليمات والمراجع.');
                }
            }
            $types = $zip->getFromName('[Content_Types].xml');
            if (! $types || str_contains(strtolower($types), 'macroenabled') || $zip->locateName('xl/workbook.xml') === false) {
                $this->invalid('يُقبل XLSX القياسي فقط.');
            }
        } finally {
            $zip->close();
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
