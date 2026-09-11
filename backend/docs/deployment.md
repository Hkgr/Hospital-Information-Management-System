# تشغيل وحدتي الأطباء والعيادات في إصدار لاحق

هذه خطوات تسليم للمسؤول عن النشر؛ **لم تُنفّذ على الإنتاج في هذا التغيير**. راجع البيئة والنسخة الاحتياطية ونافذة النشر المعتادة قبل التنفيذ. لا تستخدم migrate:fresh أو عينات الاختبار في الإنتاج.

## الاعتماديات والأصول

داخل backend ثبّت النسخ المثبتة في lock، دون composer update:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
```

PHP≥8.3 ومتطلبات Laravel13 وSanctum4، وmPDF8.3 وPhpSpreadsheet5.9 كما يحسمها composer.lock. مكتبتا التقارير وScramble موجودة أصلًا في **require**؛ لا يكفي رفع الكود مع vendor قديم. تحقق من pdo_mysql وmbstring وgd وzip وDOM/XML وfileinfo وبقية extensions التي يحددها Composer. لا تستخدم --ignore-platform-reqs.

انشر `resources/fonts/cairo/Cairo-Regular.ttf` و`Cairo-Bold.ttf` وOFL.txt، و`resources/reports/logo-ar-color.png` و`medical-line.svg` وقالب reports.directory. الخطوط ثابتة وليست WOFF2 الواجهة. يحتاج مستخدم PHP الكتابة في storage وbootstrap/cache ومنها `storage/framework/cache/directory-pdf`. لا تجعل مجلد تقارير مؤقتًا عامًا. ملفات XLSX تحتاج Cairo مثبتًا على جهاز المستخدم؛ PDF يضمّن الخط نفسه.

تحسين التقارير في مراجعة PR #9 لا يضيف اعتماد تشغيل أو migration؛ قياس التفاف النص يستعمل mPDF وخط Cairo المحليين الموجودين. افحص الخط المثبت وحجم النص في معاينة الطباعة عند تغيير برنامج فتح XLSX. [السياسة الجديدة للنص الكامل والعينات وطريقة معاينتها](directory-review.md).

## البيانات والأنواع والصلاحيات

بعد التأكد من هدف الاتصال الصحيح ضمن إجراء النشر المعتمد:

```bash
php artisan migrate --force
php artisan db:seed --class=DoctorPermissionsSeeder --force
```

الجديدة `2026_09_12_000001_add_doctor_directory_and_global_role_assignments.php` تضيف staff.description وstaff.lock_version وجدول global_user_roles فقط. لا تنسخ المستخدمين ولا تغيّر migration أصلية. rollback يحذف الوصف والنسخة والإسنادات العالمية الجديدة، لذلك لا يُستخدم كرجوع عشوائي بعد إدخال بيانات.

اضبط `CLINIC_DOCTOR_STAFF_TYPES` على أكواد الأنواع الطبية المعتمدة الموجودة فعليًا في staff_types، لا IDs. أمثلة DOCTOR وSAMPLE_DOCTOR وLIVE_DOCTOR في الاختبارات اصطناعية وليست قاموس الإنتاج. عند غياب الإعداد تمنع الوحدة الاختيار والإنشاء وتعرض السبب. لا تنشئ أنواعًا أو تخصصات تلقائيًا.

لتمكين **testadmin الموجود** عبر **super_admin المعتمد الموجود**: يستبدل المسؤول `APPROVED_FACILITY_CODE` بكود منشأة مخولة فعليًا، ويكرر --facility لكل منشأة مقصودة. نفّذ المعاينة أولًا ثم نفس الأمر مع --apply:

```bash
php artisan doctors:grant-access --user=testadmin --role=super_admin --facility=APPROVED_FACILITY_CODE --global
php artisan doctors:grant-access --user=testadmin --role=super_admin --facility=APPROVED_FACILITY_CODE --global --apply
```

الأمر يرفض مستخدمًا أو دورًا أو منشأة مفقودة/معطلة أو تعريفات صلاحيات ناقصة/معطلة. يربط تعريفات الأطباء الستة بالدور وfacility_user_roles للمنشآت المحددة، وبسبب --global يضيف global_user_roles للمستخدم والدور. بدون --global لا يوجد تفويض عالمي لتعديل الدليل. الإجراء قابل للإعادة ولا ينشئ حسابًا أو كلمة مرور أو دورًا. **تؤثر صلاحيات الدور في إسناداته القائمة أيضًا**؛ راجع مستخدمي super_admin قبل التطبيق. لا يتجاوز اسم الدور تحقق الصلاحيات. إذا كان المطلوب ربط العيادات فقط فاستخدم دورًا مناسبًا دون منح النطاق العالمي. صلاحية clinics.view تُدار بآلية العيادات الحالية لفتح تفاصيل العيادات.

## الواجهة والكاش

```bash
# frontend، بإعداد LARAVEL_API_URL الصحيح قبل البناء
npm ci
npm run build
# backend، ضمن خطوات الإصدار المعتمدة
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

احتفظ بأصول الهوية وخط Cairo المحلي في frontend. إذا غُيّر CLINIC_DOCTOR_STAFF_TYPES فأعد بناء config cache؛ لا تغيّر عقود المصادقة أو إعداد FastAPI. شغّل Next وفق إعداد standalone الحالي، وانشر public و.next/static مع ناتج standalone وفق آلية المشروع. حدّث عمليات التطبيق بعد نشر النسخة وفق سياسة التشغيل؛ لا يوجد أمر نشر تلقائي في هذه المهمة.

بعد الإصدار، ضمن صلاحية مسؤول التشغيل، افحص route:list لمسارات doctors وdocs، والدخول والصلاحيات وتنزيل ملف تجريبي مصرح. اختبارات RefreshDatabase وmigrate:fresh وملفات tests/Support تعمل على MySQL اختبارية معزولة فقط. تحقق no-dev المنفذ في هذه المهمة كان **dry-run من lock** وفحص require، وليس تثبيتًا على خادم إنتاج. يلزم قياس ذاكرة وزمن التصدير قرب الحدود على بيئة staging وبياناتها المعتمدة.
