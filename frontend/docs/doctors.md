# الأطباء والتصميم المشترك للدليل الطبي

المساران `/doctors` و`/doctors/[id]` داخل المصادقة الحالية، ورابط الأطباء يظهر لمن لديه doctors.view في منشأة واحدة على الأقل. يحتوي الجدول البيانات المهنية والتخصصات وحالة الطبيب، وعدد العيادات والمرضى من الخادم، واستعراض العيادات الحالية من الأسماء أو العدد. تعرض صفحة الطبيب الترخيص والهاتف والتخصصات والأعداد والارتباطات وتقرير التفاصيل.

تحدد capabilities القادمة من Laravel الإجراءات: إنشاء/تعديل/حذف عالمي مستقل عن ربط عيادات المنشأة. تنبّه نافذة البيانات إلى أثر التعديل والتعطيل العالمي؛ مستخدم الربط فقط يرى محرر ارتباطات دون حقول الدليل. لا يؤدي فشل الحذف إلى تعطيل تلقائي. خيارات الأنواع من CLINIC_DOCTOR_STAFF_TYPES، وغيابها يعرض تفسيرًا دون أنواع أو صفحات وهمية.

## التصميم والسلوك

التصميم المشترك للأطباء والعيادات يجمع عنوانًا واضحًا وشريط بحث وتصدير وصف فلاتر منفصلًا، ورؤوس جدول بارزة وصفوف مريحة وشارات هادئة ونصوصًا يمكن توسيعها. التفاصيل تسلسلية مع حقائق صغيرة، والنوافذ مقسمة إلى بيانات وعلاقات مع أزرار حفظ ثابتة ومحتوى قابل للتمرير. Cairo المحلي موروث في الواجهة والنوافذ. بقي AppShell وزخارفه وحركة الطي والشعار وتصميم Login كما هي.

أعيد استخدام Modal وحصر التركيز وEscape وإعادة التركيز، ومنع الإرسال والإغلاق المتكرر أثناء الحفظ. أدوات الترقيم والأعمدة والنص الطويل ومراجعة التعارض مشتركة، دون نسخ معالجة البحث أو المصادقة.

URL مصدر البحث والفلاتر والترقيم المعتمدة. يعيد الرجوع والتقدم القيم من إدخالات تاريخ فعلية؛ مؤقت300ms يرتبط بالسياق ويُلغى عند التنقل أو تغيير المنشأة. تغيير البحث/الفلاتر يعيد الصفحة للأولى، والعودة من التفاصيل تحفظ الفلاتر. التصدير ينتظر اعتماد البحث الظاهر ويرسل الفلاتر والأعمدة نفسها، ويشمل جميع النتائج. لا تُعرض بيانات طلب سابق أثناء التحميل أو بعد تبديل المنشأة.

عند التعارض تبقى المسودة واختيارات الارتباطات. جلب أحدث نسخة يقرأ الطبيب وارتباطاته الحالية وحالة العناصر التي غيّرها المستخدم ثم يعيد التحقق من النسخة. يختار المستخدم الحقول والروابط التي يريد تطبيقها صراحةً؛ أحدث البيانات هي الافتراضية، ولا حفظ أو دمج تلقائي. تُحسب الفروق مقابل الحالة الجديدة مع حفظ الروابط المخفية. يحدّث الجلب الجدول/التفاصيل، والفشل أو التعارض الثاني يبقي المسودة. الإغلاق وتغيير المنشأة يلغيان الطلب ويمنعان النتائج المتأخرة.

عقود API وحدود الوصول والأعداد والتقارير في [دليل Laravel](../../backend/docs/doctors-api.md)، ومتطلبات Composer والخطوط والإسناد إلى testadmin في [دليل النشر](../../backend/docs/deployment.md).

## الصور والعينات

صور الواجهة ببيانات اصطناعية واستجابات متصفح محاكاة. الاختبار HTTP الحي مع Laravel وMySQL منفصل ومذكور أدناه.

| الأطباء | 390px | 768px | 1440px |
| --- | --- | --- | --- |
| القائمة | [صورة](screenshots/doctors/list-390.png) | [صورة](screenshots/doctors/list-768.png) | [صورة](screenshots/doctors/list-1440.png) |
| المحرر | [صورة](screenshots/doctors/editor-390.png) | [صورة](screenshots/doctors/editor-768.png) | [صورة](screenshots/doctors/editor-1440.png) |
| العيادات المرتبطة | [صورة](screenshots/doctors/clinics-390.png) | [صورة](screenshots/doctors/clinics-768.png) | [صورة](screenshots/doctors/clinics-1440.png) |
| التفاصيل | [صورة](screenshots/doctors/detail-390.png) | [صورة](screenshots/doctors/detail-768.png) | [صورة](screenshots/doctors/detail-1440.png) |

| العيادات | 390px | 768px | 1440px |
| --- | --- | --- | --- |
| القائمة | [صورة](screenshots/clinics/list-390.png) | [صورة](screenshots/clinics/list-768.png) | [صورة](screenshots/clinics/list-1440.png) |
| المحرر | [صورة](screenshots/clinics/add-390.png) | [صورة](screenshots/clinics/add-768.png) | [صورة](screenshots/clinics/add-1440.png) |
| الأطباء المرتبطون | [صورة](screenshots/clinics/doctors-390.png) | [صورة](screenshots/clinics/doctors-768.png) | [صورة](screenshots/clinics/doctors-1440.png) |
| التفاصيل | [صورة](screenshots/clinics/detail-390.png) | [صورة](screenshots/clinics/detail-768.png) | [صورة](screenshots/clinics/detail-1440.png) |

عينات كاملة: [الأطباء](../../backend/docs/samples/doctors) و[العيادات](../../backend/docs/samples/clinics). لكل وحدة40 سجلًا اصطناعيًا، قائمة PDF/XLSX بأعمدة كثيرة وأخرى بعمودين، وتفاصيل بتوصيف طويل. لا تحتوي العينات مرضى حقيقيين. [وصف العينات](../../backend/docs/samples/README.md).

PDF يضمّن Cairo Regular/Bold ثابتين؛ تحققت أداة المراجعة من FontFile2 وأسماء الخطوط ومن GSUB/GPOS وغياب fvar في TTF. XLSX يحدد اسم Cairo فقط ويتطلب تثبيته على جهاز العرض. فُتحت جميع ملفات XLSX في Microsoft Excel16 محلي مع Cairo مثبت، وفُحص اسم الخط في كل ورقة، ثم أُخرجت معاينات PDF من Excel نفسه. صور صفحات [PDF الأطباء وExcel](screenshots/doctors/pdf) و[PDF العيادات وExcel](screenshots/clinics/pdf) توثق المراجعة. أصلحت المعاينة زخرفة SVG غير الصحيحة وعرض صفر كفراغ وتصغير الأعمدة الكثيرة في Excel.

## إعادة الاختبارات والمسار الحي

كل اختبار قاعدة بيانات يستخدم `.env.testing` محلية وMySQL معزولة؛ لا SQLite. ابدأ في backend:

```bash
php artisan test-db:check --connect --env=testing
php artisan test --env=testing
```

لا تُشغّل الأوامر التدميرية إلا بعد الحاجز وعلى قاعدة اختبارية مصرح بها. اختبارات RefreshDatabase تمسح بيانات تلك القاعدة. لتجربة HTTP الحية بعد انتهاء اختبارات Laravel:

```bash
# backend؛ ينشئ بيانات اصطناعية فقط بعد الحاجز، ويكتب اعتمادها في storage/framework/testing المتجاهل
php tests/Support/prepare-doctor-live.php
# شغّل Laravel اختبارياً على 127.0.0.1:8001 مع CLINIC_DOCTOR_STAFF_TYPES=LIVE_DOCTOR في هذه العملية
php artisan serve --env=testing --host=127.0.0.1 --port=8001
# frontend، في نافذة ثانية
node tests/doctor-live.mjs
# backend
php tests/Support/verify-doctor-live.php
```

المسار الحي يسجل الدخول، وينشئ طبيبًا دون عيادات، ثم يربط عيادتين ويقرأهما ويتحقق من أعداد ونسخ جانب العيادة، ويرفض حفظ نسخة قديمة، وينزّل PDF/XLSX خاصين. يتحقق السكربت الأخير من الصفوف والتدقيق في MySQL. لا يوجد interception أو mock في هذا المسار. بيانات الإعداد تبقى في قاعدة الاختبار إلى أن تُعاد تهيئتها باختبارات مصرح بها؛ لا تشارك JSON المحلي الذي يحوي بيانات الاعتماد.

للمتصفح: ابنِ وشغّل Next محليًا، واضبط TEST_BASE_URL وPLAYWRIGHT_CHANNEL=chrome إذا استعملت Chrome المثبت:

```bash
npx tsc --noEmit
npm run lint
npm run build
node --test tests/doctors.test.mjs tests/clinics.test.mjs tests/app-shell.test.mjs tests/dashboards.test.mjs tests/auth-ui.test.mjs
```

المتصفح يختبر السلوك الحقيقي للواجهة مع API محاكاة؛ لا يُقدّم ذلك على أنه تكامل HTTP حي. يغطي الأذونات والتعارض وإعادة الجلب والروابط المخفية والإرسال المتكرر والتركيز وتاريخ المتصفح وإلغاء debounce وتبديل المنشأة والتصدير والحذف والاستجابة للأحجام الثلاثة.

لتوليد التقارير، شغّل `php tests/Support/generate-directory-samples.php` في backend؛ ينفّذ الحاجز ويعيد كل بياناته بـrollback. مراجعة Excel على Windows: `powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests/Support/review-excel-reports.ps1` (استثناء سياسة لهذه العملية فقط). يفتح السكربت العينات للقراءة، ويعطل macros/events/links، ويختار Microsoft Print to PDF داخل جلسة Excel الخاصة به بعد فتح المصنف؛ لا يغيّر الطابعة الافتراضية ولا يطبع ورقًا.

`node tests/render-directory-reports.mjs` في frontend يرسم كل صفحات العينات. يعتمد مساعد المعاينة الاختياري على PDF.js6.3.289 و@napi-rs/canvas1.0.9 داخل `.superdesign/pdf-tools/node_modules`، وليس اعتماد تشغيل للتطبيق. عند الحاجة جهزه محليًا بـ`npm install --prefix .superdesign/pdf-tools --no-save pdfjs-dist@6.3.289 @napi-rs/canvas@1.0.9`. لا تعتمد عمليات build أو الاختبارات الأساسية على هذا المجلد المتجاهل.

## نتائج التحقق الفعلية

| الفحص | النتيجة الفعلية |
| --- | --- |
| `test-db:check --connect --env=testing` | نجح: APP_ENV=testing، mysql، hospital_testing، 127.0.0.1:13306، قاعدة معزولة مصرح بها |
| `migrate:fresh --seed --env=testing` | نجح بعد الحاجز؛ شملت migration الجديدة. أعاد اختبار AuthMigrationTest دورة fresh/seed وrollback لكل migrations ثم migrate/seed بنجاح |
| `php artisan test --env=testing` | **71 ناجحًا، 2326 تحققًا**؛ منها13 للعيادات و10 للأطباء واختبار قالب مشترك |
| اختبارات المتصفح الخمس | **52 ناجحًا، 0 فشل، 1 متجاوز** من53؛ منها20 للعيادات و10 للأطباء، Chrome على Next المحلي3101 |
| `npx tsc --noEmit` | نجح |
| `npm run lint` | نجح بلا تحذيرات |
| `npm run build` | نجح؛ /doctors و/doctors/[id] ضمن بناء Next16.3.4 |
| `php vendor/bin/pint --dirty --test` | نجح، مع فحص صريح للملفات الجديدة أيضًا |
| `composer validate` | نجح |
| `composer check-platform-reqs --no-dev` | نجح على PHP8.4.14 |
| `composer install --no-dev --dry-run --no-scripts --no-interaction` | نجح من lock؛ مكتبتا التقارير وScramble ضمن require. لم يحدث تثبيت no-dev على خادم |
| `route:list --path=api -v --env=testing` | نجح،30 مسارًا، منها12 للأطباء مع Sanctum والحساب الفعال وقدرة api |
| `route:list --path=docs -v --env=testing` | نجح، مساران للتوثيق مع الحماية الحالية؛ مخططات الأطباء اختُبرت مقابل الردود الفعلية |
| `git diff --check` و`git diff --cached --check` | نجحا بعد إزالة مسافة طرفية من نسخة ترخيص الخط المضافة |
| `node tests/doctor-live.mjs` | نجح عبر HTTP الحقيقي: دخول، إنشاء دون عيادات، ربط عيادتين، أعداد ونسخ من الطرفين،409 للنسخة القديمة، تنزيل PDF/XLSX خاصين |
| `php tests/Support/verify-doctor-live.php` | نجح: تحقق من صف staff والنسخة والارتباطين والتدقيق في MySQL |
| توليد العينات ومعاينتها |25 صفحة PDF من Laravel و30 صفحة معاينة Excel، فحص تضمين/اسم Cairo، ومراجعة صور كل الصفحات؛24 صورة للواجهات والنوافذ على الأحجام الثلاثة |

كشف تشغيل سابق للمجموعة عن عدم ثبات اختبار عدد الاستعلامات بسبب UPDATE الذي يجريه Sanctum لوقت استخدام التوكن عند عبور ثانية. أصبح الاختبار يقارن عدد SELECT بين قائمة صغيرة وأكبر، فنجح التشغيل النهائي كاملًا؛ لم تُخفّف حدود N+1. صححت الاختبارات أثناء التنفيذ أيضًا اختيار ورقة Excel النشطة وحفظ print area بصيغة المكتبة الصحيحة. فشل استخدام Chromium المرفق لغيابه محليًا؛ استُخدم Chrome المثبت بدلًا منه. احتاج Excel اختيار طابعة PDF بعد فتح المصنف لتجنب نافذة إعداد الطابعة الافتراضية غير المتاحة؛ نجحت المعاينات بعد التصحيح.

اختبار الدخول الحي الاختياري في auth-ui متجاوز لعدم ضبط بياناته؛ مسار تسجيل الدخول وCRUD والتقرير عبر Laravel الحي المذكور أعلاه مستقل عنه. لم يُشغّل اختبار ضغط متزامن لعدد كبير من اتصالات MySQL أو نشر staging؛ اختبار التزامن هنا يثبت النسخ المتعارضة من الطرفين وحفظ التاريخ وعزل المنشآت. يلزم قياس استهلاك التصدير قرب الحد على بيئة staging. لا تغيير في FastAPI أو الإنتاج، ولا دمج أو نشر.
