# إدارة العيادات

Laravel يملك بيانات العيادات والمصادقة والصلاحيات والتقارير. تستخدم Next.js نفس Sanctum Bearer وعقود الدخول الحالية. لا يوجد تغيير في FastAPI أو إدارة مستقلة للأطباء أو المرضى.

## الإعداد والنشر لاحقًا

1. تثبيت الاعتمادات من `composer.lock`: mPDF 8.3.1 وPhpSpreadsheet 5.9.0، مع متطلبات PHP التي يتحقق منها Composer (ومنها mbstring وgd وzip وXML).
2. تشغيل migration الجديدة `2026_09_11_000001_add_clinic_description_and_version.php` عبر إجراءات النشر المعتمدة لاحقًا. تضيف فقط `clinics.description` و`clinics.lock_version`؛ rollback يحذف هذين الحقلين ومحتوياتهما. لا تغيّر migrations المنشورة.
3. تشغيل `php artisan db:seed --class=ClinicPermissionsSeeder` لإنشاء تعريفات الصلاحيات المفقودة. العملية قابلة للإعادة، لا تمنح صلاحيات ولا تعيد تفعيل تعريف معطل. في الاختبار أضف `--env=testing` واجتز الحاجز أولًا.
4. يختار مسؤول الصلاحيات الأدوار المناسبة، ويربط تعريفات `clinics.view/create/update/delete/export` عبر `role_permissions` ثم المستخدم والدور والمنشأة عبر `facility_user_roles`. لا يوجد تجاوز باسم ADMIN. كل عملية تتطلب `clinics.view` إضافة إلى صلاحيتها داخل المنشأة نفسها.
5. اضبط `CLINIC_DOCTOR_STAFF_TYPES` محليًا بقائمة مفصولة بفواصل من **أكواد staff_types.code المعتمدة فعليًا**. لم يتضمن المستودع قاموسًا معتمدًا لهذه الأكواد، لذلك القيمة الافتراضية فارغة وتمنع اختيار أي طبيب حتى ضبطها؛ لا تخمين لـIDs أو الأسماء العربية. أكواد DOCTOR وSAMPLE_DOCTOR في الاختبارات اصطناعية فقط، وليست اعتمادًا إنتاجيًا.
6. أعد بناء frontend، واضبط `LARAVEL_API_URL` كما في الإعداد الحالي. rewrites تقتصر على مسارات العيادات المحددة. يحتاج Laravel صلاحية الكتابة في `storage/framework/cache/clinic-pdf`؛ لا تحفظ التقارير في public.

لم يُنفّذ نشر أو تشغيل migrations على الإنتاج ضمن هذه المهمة.

## العقود والصلاحيات

جميع المسارات تبدأ بـ`/api/clinics` وتطبق `auth:sanctum → account.active → abilities:api` ثم صلاحيات المنشأة من قاعدة البيانات في كل طلب. أرسل `Authorization: Bearer …` و`Accept: application/json`. كل الاستجابات، بما فيها الأخطاء والتقارير، `Cache-Control: private, no-store` و`Vary: Authorization`.

| الطريقة والمسار | الصلاحية إضافة إلى view | النتيجة |
| --- | --- | --- |
| GET `/` | — | `{data: Clinic[], meta: {page,per_page,total,last_page}}` |
| POST `/` | create | 201 `{data: Clinic}` |
| GET `/{clinic}` | — | `{data: Clinic}` |
| PUT `/{clinic}` | update | `{data: Clinic}` |
| DELETE `/{clinic}` | delete | 204 أو 409 عند الارتباط |
| POST `/{clinic}/deactivate` | update | تعطيل مستقل، `{data: Clinic}` |
| GET `/{clinic}/doctors` | — | أطباء حاليون، بحث وترقيم |
| GET `/options/doctors` | — | أطباء مؤهلون، بحث وترقيم، `doctor_types_configured` |
| GET `/options/specialties` | — | تخصصات فعالة `{data: [{id,name_ar}]}` |
| GET `/export/{format}` | export | ملف xlsx أو pdf |
| GET `/{clinic}/report` | export | تقرير العيادة PDF |

`facility_id` مطلوب في جميع الطلبات؛ عدم امتلاك صلاحية المنشأة يعطي 403 دون كشف وجودها. معرّف عيادة يخص منشأة أخرى يعامل كغير موجود (404). لا تُجمع صلاحيات منشأتين.

طلب الإضافة:

```json
{"facility_id":1,"code":"001","name_ar":"عيادة اختبارية","description":"توصيف العيادة","specialty_id":null,"is_active":true,"doctor_add_ids":[]}
```

الكود مطلوب ≤40 محرفًا وفريد داخل المنشأة، والاسم مطلوب ≤200، والتوصيف ≤10000. عند التعديل أرسل الحقول نفسها و`lock_version` الحالي، و`doctor_add_ids` / `doctor_remove_ids` كتغييرات صريحة (≤200 لكل قائمة). الحذف والتعطيل يستقبلان `facility_id` و`lock_version` فقط. الحقول غير المدرجة لا تدخل mass assignment.

`Clinic` يحتوي فقط: id، facility_id، code، name_ar، description، specialty `{id,name_ar}` أو null، is_active، lock_version، doctor_count، patient_count، doctors_preview (حتى 3 أسماء ومعرّفات)، patient_count_definition. لا يحتوي بيانات مرضى أو حسابات الأطباء.

عقد الطبيب: `{id,code,name,starts_on,is_linked,specialties:[{id,name_ar}]}`. عند طلب الخيارات يمكن إرسال `clinic_id` لمعرفة الاختيارات الحالية؛ يتحقق الخادم من منشأة هذه العيادة أيضًا. staff دليل عام في المخطط الحالي بلا facility_id، لذا يتاح الطبيب الفعال ذو النوع الطبي الفعال المعتمد للمنشآت التي يسمح المستخدم بإدارتها، دون اختراع علاقة منشأة جديدة.

القائمة: `search` (الكود/الاسم/التوصيف، ≤200)، `status=active|inactive`، `doctor_id`، `specialty_id`، `sort=code|name_ar|doctor_count|patient_count|is_active`، `direction=asc|desc`، `page≥1`، `per_page=1..100` (افتراضي20). يضاف id كترتيب ثانوي ثابت. البحث يستخدم LIKE على الخادم، لذا `%` و`_` لهما معنى wildcard؛ لا يوجد SQL نصي من المستخدم. رقم العرض `(page-1)*per_page+index+1`.

## الفترات والأعداد والتدقيق

- الفترة نصف مفتوحة: starts_on شامل وends_on غير شامل. الارتباط الحالي يبدأ قبل/في يوم المنشأة، وينتهي بعده أو لا نهاية له. الحساب يستخدم منطقة المنشأة الزمنية. `ends_on=starts_on` فترة صفرية صالحة للإضافة والإزالة في اليوم نفسه.
- الإزالة تنهي الارتباط الحالي ولا تمسحه. الإعادة في اليوم نفسه تفتح سجل اليوم نفسه لتوافق القيد الفريد الحالي؛ تحفظ audit_logs كل انتقال. الماضي مغلق كما هو. الارتباط المجدول الذي سيُتداخل معه أو وجود عدة فترات متداخلة يرفض الإضافة بـ409. لا تستخدم العملية sync ولا تستبدل الخيارات غير المحملة.
- قفل سجل العيادة داخل transaction وlock_version يمنعان فقدان تعديل متزامن. المسارات الأخرى التي قد تكتب clinic_staff مستقبلًا يجب أن تتبع القفل والسياسة نفسها.
- عدد الأطباء distinct للموظفين الفعالين ذوي أنواع طبية فعالة ومعتمدة وارتباط حالي. القائمة والنافذة والتقرير تستعمل ClinicCounts نفسها. تكرارات الإرث تُزال من العرض والعدد.
- عدد المرضى `COUNT(DISTINCT visits.patient_id)` حيث `visits.clinic_id` للعيادة والمنشأة مطابقة و`status=complete` و`voided_at IS NULL`. هذا لا يمثل معاينات عدة عيادات داخل زيارة واحدة؛ لا يُستنتج من الطبيب. الحساب معزول في ClinicCounts لتمديده لاحقًا.
- الاستعلامات مجمعة، والخيارات والتفاصيل لا تعرض سوى الحقول اللازمة. اختبار عدد الاستعلامات يثبت عدم نموها مع عدد صفوف القائمة.
- الحذف يتحقق من الزيارات وكل clinic_staff بما فيه التاريخ وstaff_work_days داخل transaction. المفاتيح الأجنبية RESTRICT تحمي أي مراجع أخرى أو سباق إضافة مرجع. لا cascade. audit_logs يحتفظ بإنشاء وتعديل وتغيير أطباء وتعطيل وحذف وتصدير، مع المنفّذ والمنشأة ومعرّف طلب مُولّد من الخادم.

## التقارير

التصدير يستعمل استعلام القائمة والفلاتر والترتيب نفسه، ويهمل ترقيم الصفحة. `columns[]=number|code|name_ar|description|doctors|doctor_count|patient_count` يحدد الأعمدة المرئية؛ لا يوجد عمود إجراءات في التقرير.

- XLSX حقيقي بـPhpSpreadsheet: RTL، شعار المشفى، ترويسة وبيانات الإصدار، autofilter، freeze عند A9، وعروض مناسبة. الأكواد وكل النصوص TYPE_STRING لحفظ الأصفار ومنع formula injection. الأعداد وحدها رقمية.
- PDF بـmPDF وخط DejaVu Sans المحلي المرفق بالمكتبة مع OTL للعربية. يحتفظ frontend بخط Cairo المحلي. الشعار PNG مشتق دون تغيير من أصل الهوية SVG في frontend. كل النصوص تُهرب في Blade، وHTTP محظور أثناء التوليد. الجدول يكرر thead، والتقرير المفرد يحمل كامل التوصيف والأطباء دون أي أسماء مرضى.
- رقم التقرير `CL-{facility_id}-{year}-{sequence}` يصدر بتحديث number_sequences تحت lockForUpdate؛ قد توجد فجوات عند فشل تصدير، ولا يعاد استخدام الرقم. المنفّذ من المستخدم المصادق، والوقت بمنطقة المنشأة. لا يوجد رقم أو منفّذ موثوق من العميل.
- حد 1000 عيادة و5000 ارتباط طبيب حالي. تجاوز الحد يعطي 422 EXPORT_LIMIT_EXCEEDED دون قطع النتائج. يحترم Excel حد 32767 محرفًا للخلية.
- PDF القائمة يرفض خلية توصيف >1800 محرف أو أسماء أطباء >600 بـ422 PDF_LAYOUT_LIMIT_EXCEEDED بدل تصغير الخط إلى حجم غير مقروء. يمكن إخفاء العمود أو استخدام Excel أو PDF المفرد. لا يطبق حد التوصيف هذا على التقرير المفرد.
- الملفات تُولّد وتُعاد في الذاكرة مع Content-Disposition وX-Report-Number؛ ليست روابط عامة. number_sequences وaudit_logs معاد استخدامهما. report_runs الحالي مخصص لإصدارات تقارير مرتبطة بفترات تقارير طبية، لذلك لا تُنشأ فيه سجلات ناقصة لتقرير دليل متزامن، ولا حاجة إلى export_jobs ما دام الحد يمنع التصدير الكبير.

الأخطاء: 401 UNAUTHENTICATED، 403 ACCOUNT_INACTIVE (يلغي التوكنات) / MISSING_API_ABILITY / CLINIC_ACCESS_DENIED، 404 CLINIC_NOT_FOUND، 409 CLINIC_VERSION_CONFLICT / CLINIC_PERIOD_CONFLICT / CLINIC_REFERENCED، 422 validation أو حدود التصدير، 500 CLINICS_UNAVAILABLE. الأخطاء الداخلية تُبلّغ لآلية Laravel مرة واحدة وتُحجب تفاصيلها عن العميل.

توثيق Scramble التفاعلي الموجود `/docs/api` وOpenAPI `/docs/api.json` يشمل العقود والحقول والحدود والصلاحيات وأنواع الملفات. اختبارات العقود تقارن الاستجابات الفعلية بالمخططات، وتفرق بين متطلبات الإضافة والتعديل.

## الاختبار والعينات

استخدم `.env.testing` محلية مع mysql وقاعدة مستقلة تنتهي `_testing` أو تبدأ `test_`، والمضيف المصرح. لا أسرار في Git ولا SQLite. قبل أي اختبار تكاملي:

```bash
php artisan test-db:check --connect --env=testing
php artisan test --env=testing
php tests/Support/generate-clinic-samples.php
```

يضبط phpunit.xml ذاكرة عملية الاختبار إلى 256MB لأن المجموعة الكاملة تجمع تحليل OpenAPI وتوليد وقراءة PDF وXLSX حقيقية في عملية واحدة؛ حد الجهاز السابق 128MB لم يكفِ. لا يغيّر ذلك php.ini أو إعدادات الإنتاج. تُحرر مراجع مكتبات التقارير بعد التوليد، وتظل حدود عدد الصفوف والارتباطات مطبقة؛ يلزم قياس ذاكرة التصدير على أحجام البيانات الفعلية عند تجهيز بيئة النشر.

سكربت العينات يحمّل `.env.testing` ويطبق الحاجز قبل الاتصال، ويعيد جميع بياناته الاختبارية بـrollback؛ ينتج فقط ملفات اصطناعية في [samples/clinics](samples/clinics). الأرقام في هذه العينات تجريبية، وتعود sequences الخاصة بها مع rollback. توجد قائمة Excel وقائمة PDF متعددة الصفحات وتقرير عيادة بتوصيف طويل. نتائج التحقق الفعلية في توثيق frontend وPR.

التكامل القادم لمعاينات متعددة داخل زيارة، وإدارة الأطباء والمرضى، ومنح الصلاحيات عبر واجهة، والتصدير المؤجل الكبير، خارج نطاق هذه الوحدة. قبل تمكين اختيار الأطباء في بيئة أخرى يجب اعتماد أكواد الأنواع وإعدادها، ومراجعة أي بيانات ارتباط قديمة مقابل تعريف الفترة الموثق.
