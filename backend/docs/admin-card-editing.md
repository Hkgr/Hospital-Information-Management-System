# إداري المشفى: تعديل بطاقات المرضى

## العقد

دور `hospital_admin` يملك افتراضيًا العرض والتصدير والتدقيق فقط، فلا يستطيع تعديل البطاقة. يمنحه الأمر `access:admin-card-editing` الصلاحيات التالية، **إضافةً فقط**:

| المهمة | الصلاحيات |
| --- | --- |
| القراءة | `dossiers.medical.view`, `dossiers.visits.view` |
| الأقسام الطبية | `dossiers.medical.update`, `dossiers.visits.draft.update`, `dossiers.diagnoses.update`, `dossiers.services.update`, `dossiers.procedures.update`, `dossiers.prescriptions.update`, `dossiers.outcomes.update`, `dossiers.pathology.update` |
| العلاج | `dossiers.treatment.view`, `dossiers.treatment.update`, `dossiers.treatment.schedule.update`, `dossiers.treatment.administration.correct`, `dossiers.treatment.dispensing.correct` |

لا يمنح الأمر تعديل البيانات الشخصية (`dossiers.personal.update`) ولا إنشاء البطاقة ولا الحذف ولا الإكمال ولا الإبطال. تصحيح الهوية المشتركة يبقى عبر مسار التصحيح والمراجعة. تبقى الزيارة المكتملة والوقائع المبطلة غير قابلة للتعديل لأي دور، وعزل المنشأة والنسخ والتدقيق كما هي.

## التشغيل

لا يحتاج migration ولا Seeder. الأمر لا يحذف أي منحة، ولا يغيّر الأدوار الأخرى أو الإسنادات أو الحسابات. يفشل قبل أي كتابة إذا كان الدور غير موجود أو معطّلًا أو محميًا (مدير النظام)، أو كان أي تعريف مطلوب غير موجود أو معطّلًا.

```bash
# معاينة فقط، دون كتابة
php artisan access:admin-card-editing

# تطبيق بعد المراجعة؛ يتأثر كل من يحمل الدور ضمن إسناداته الموجودة
php artisan access:admin-card-editing --apply --facility=<ACTUAL_FACILITY_ID> --execution-reference=<CHANGE_REFERENCE_AND_OPERATOR>
```

`--facility` هو المشفى الذي يُسجَّل فيه التدقيق فقط، ولا يحصر التعديل في بعض إسنادات الدور. يُسجَّل السابق والجديد والإضافات ومرجع المشغّل مع `actor_id=NULL`، وتزيد نسخة الدور بواحد. إعادة التنفيذ على الحالة المطابقة لا تضيف منحًا ولا تدقيقًا ولا تغيّر النسخة.

لا يستدعيه بدء التطبيق ولا `DatabaseSeeder`، و`PermissionMatrixPhaseOneSeeder` لا يعيد منح ما سحبه المسؤول لاحقًا. البديل اليدوي هو محرر الأدوار في الواجهة (المستخدمون ← الأدوار والصلاحيات) بحساب مدير النظام. بعد التطبيق أعد تحميل الواجهة وتحقق بحساب الأدمن من ظهور إجراءات التعديل في زيارة غير مكتملة.
