# تقارير بنك الدم واستكمال الدليل

تنشئ Laravel ملفات PDF وXLSX باستخدام محرك DirectoryReport/mPDF، وأصول الهوية وخط Cairo المحلي وDirectorySpreadsheet/PhpSpreadsheet الموجودين. لا تتغير المصادقة أو قبول التبرعات أو بياناتها العلاجية. لا ينشئ تصدير ملف مستفيد واقعة نقل دم، ولا ينشئ إنشاء الملف تبرعًا.

## العقد والصلاحيات

كل المسارات أدناه GET تحت `/api/blood-bank`، وعبر Next تحت `/hospital-api/blood-bank`، مع `facility_id` مطلوب. `format` حصريًا `pdf` أو `xlsx` والمعرّفات رقمية:

| المسار | المحتوى |
|---|---|
| `/export/{format}` | جميع الملفات المطابقة للبحث والنوع والترتيب؛ يتجاهل ترقيم الصفحة |
| `/{kind}/{item}/report/{format}` | ملف `donor` أو `recipient`، بياناته وفحوصه وحسب النوع تبرعاته |
| `/donor/{donor}/donations/{donation}/report/{format}` | واقعة واحدة وهوية المتبرع المرجعية |

تتطلب جميعها Bearer Sanctum بقدرة `api` وحسابًا فعالًا و**blood_bank.view + blood_bank.export** في المنشأة الفعالة. يُرفض معرّف من منشأة أخرى، والتفويض العالمي لبحث المرضى لا يُمنح بالتصدير؛ قراءة المريض المرتبط تتبع عقد عرض ملف بنك الدم القائم. الاستجابات `private, no-store` وأسماء الملفات `BB-{facility}-{year}-{sequence}.{format}`، مع Content-Type مطابق و`X-Report-Number`. الطلبات تدقَّق كعملية تصدير دون تسجيل محتوى الفحوص أو ملف التقرير في السجل.

الفلاتر المشتركة مع الجدول: `search` (اسم كامل/كود مع `%` و`_` حرفيين)، `kind`، `sort=code|name|updated_at` و`direction=asc|desc`. التصدير لا يقتصر على `page/per_page`. الحد **1000 ملف أو واقعة**؛ الزيادة تعيد 422 `EXPORT_LIMIT_EXCEEDED` دون ملف جزئي. العدد هو **عدد الملفات**، وليس أشخاصًا فريدين. تظل أزرار القائمة محجوبة أثناء البحث غير المعتمد أو تحميل أحدث طلب أو فشله أو إعادة التحديث بعد الحفظ؛ الحماية موجودة أيضًا في دالة التنزيل. التقارير الفردية تتبع تحميل الملف والواقعة فقط.

التقارير العامة لا تتضمن فحوصًا. الفردية تتضمن أسماء الفحوص المسجلة وحالاتها فقط، دون طرق أو نتائج تاريخية. التبرعات الفعلية تُحتسب مرة واحدة حسب الواقعة بكودها الحالي، والملغى ظاهر ومنفصل في العد. لا جمع للكميات؛ `blood_donations.units` هي وحدة السجل الحالية، وليس لها في المخطط تعريف ملليلتر أو نوع حجم آخر، لذلك لا نفترض ذلك. أسماء الأكواد السابقة تبقى قابلة للبحث عبر الواجهة، ولم تُضف كصفوف وقائع إلى التقرير.

الأكواد والهواتف والنصوص XLSX typed strings، والتواريخ أرقام Excel بتنسيق تاريخ والكميات عشرية. النصوص الأطول من عرض الصف تستكمل وفق قياس Cairo في أوراق النصوص دون حذف القيمة الكاملة؛ رؤوس متكررة، تجميد وتصفية، RTL وطباعة بعرض صفحة دون ضغط الارتفاع لصفحة واحدة. PDF يستخدم تقسيمًا يحفظ النص والترويسات عبر الصفحات، مع الاتجاه المناسب للأكواد والأرقام.

## خطوات تشغيل للمشغّل بعد اعتماد PR (لم تُنفَّذ على الإنتاج)

1. خذ نسخة احتياطية معتمدة وافحص `php artisan migrate:status` ونسخة المحرك بإجراءات التشغيل المعتادة. MariaDB 10.11.18 يعمل هنا عبر driver `mysql`.
2. الترحيل `2026_09_14_000002_refine_blood_bank_profiles` قد يكون توقف جزئيًا. إصدار هذا الإصلاح يتحقق من نوع الأعمدة وقابليتها لـNULL والفهارس وتعريف CHECK المتوقع قبل الاستكمال، ولا يعيد إنشاء الأعمدة الموجودة. يستخدم `DROP CONSTRAINT` في MariaDB و`DROP CHECK` في MySQL. إذا خالف قيد يدوي التعريف المعروف، يتوقف برسالة واضحة؛ راجع القيد يدويًا، ولا تُضف صفًا إلى migrations ولا تسقط الأعمدة لتجاوز الخطأ. إصلاح صيغة rollback في الترحيل الأول لا يغير تعريف البيانات.
3. بعد المراجعة نفّذ:

```bash
php artisan migrate --force
php artisan db:seed --class=SyrianCitiesSeeder --force
php artisan db:seed --class=BloodBankPermissionsSeeder --force
```

لا يوجد migration جديد. `SyrianCitiesSeeder` إجراء مستقل ولا يستدعي DatabaseSeeder. يشترط وجود دليل المحافظات السورية `country_code=SY`. إن لم يكن دليل بنك الدم السابق قد هُيّئ، راجعه أولًا ثم شغّل `BloodBankReferenceSeeder` صراحة وفق توثيق بنك الدم؛ لا يُشغّل تلقائيًا عند فتح الصفحة. تفاصيل المدن وأعدادها والمصادر والتعارضات: [syrian-cities.md](syrian-cities.md).

4. الصلاحية الجديدة هي `blood_bank.export`. Seeder يعرّفها فقط، ولا يُسندها لأي حساب أو دور. عبر إدارة الصلاحيات المعتمدة/مشغّل مخوّل: اختر دورًا **مخصصًا للمنشأة المقصودة**، تحقق من عضويته الفعلية في `facility_user_roles` ومن `blood_bank.view`، ثم أضف الربط بين معرّف الدور ومعرّف الصلاحية في `role_permissions`. لا تعِد استخدام دور مشترك في منشآت أخرى دون مراجعة أثر المنح، ولا تضف تفويضًا عالميًا أو صلاحيات بحث المرضى. تحقق باستخدام الحساب المقصود أن `/blood-bank/options` يعيد `capabilities.export=true` فقط في نطاقه المصرح.
5. اضبط `LARAVEL_API_URL` حسب إعداد النقل الموجود ثم أعد `npm ci` و`npm run build` في frontend. يتطلب Next بناءً جديدًا لتضمين التحويلات، ونسخ `public` و`.next/static` إلى standalone حسب آلية التشغيل الحالية. لا متغير بيئة جديد لهذا القسم.

في مجلد إصدار جديد على Linux، بعد إعداد البيئة المعتمد، أوامر البناء والنسخ هي:

```bash
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan db:seed --class=SyrianCitiesSeeder --force
php artisan db:seed --class=BloodBankPermissionsSeeder --force
php artisan config:cache
php artisan route:cache
cd ../frontend
npm ci
# LARAVEL_API_URL موجود في بيئة البناء بالقيمة المعتمدة الحالية.
npm run build
mkdir -p .next/standalone/.next
cp -R public .next/standalone/
cp -R .next/static .next/standalone/.next/
```

تشغيل الإصدار/إعادة تشغيل الخدمة يتم بواسطة آلية النشر المعتمدة خارج هذه المهمة. لا تُنسخ إعدادات الاختبار أو ملفات عيناتها إلى الإنتاج. راجع أيضًا [deployment.md](deployment.md) لاعتماديات PHP وتهيئة التخزين.

## تحقق محلي قابل للتكرار

ابدأ بحاجز `php artisan test-db:check --connect --env=testing`. يجب أن يكون الاتصال mysql وقاعدة منفصلة مع إعدادات `TEST_DATABASE_*` المؤكدة. اختبرنا محركي MySQL 8.4.7 وMariaDB 10.11.18 منفصلين؛ لا SQLite. لا تشغّل اختبارات RefreshDatabase بالتزامن مع تجهيز عينات HTTP على القاعدة نفسها.

```bash
php artisan test --env=testing --filter="BloodBank|SyrianCities|DirectoryReportTest"
php vendor/bin/pint --dirty --test
```

تشغيل HTTP الاصطناعي يستخدم `tests/Support/blood-bank-server.php` مع `.env.testing`، وبناء Next standalone على 3105 موجهًا إليه على 8015. ثم داخل frontend:

```bash
node --test tests/blood-bank-live.test.mjs
npx playwright install chromium --only-shell
node --test tests/blood-bank-reports-live.test.mjs
npx tsc --noEmit
npm run lint
npm run build
git diff --check
```

الاختبار الثاني يحفظ عينات التقارير واللقطات محليًا في `.superdesign/blood-bank-reports/`، ويستخدم طلبات Laravel فعلية حتى عند تأخير تسليم الرد للمتصفح. ملفات التوكنات الاصطناعية تبقى في التخزين المحلي المتجاهَل وتُلغى بعد الاختبار. نتائج التنفيذ والمعاينة الفعلية تسجّل في وصف PR؛ لا يُستنتج نجاح المعاينة من HTTP 200.

فحص ملفات العينات: `php tests/Support/read-blood-bank-samples.php` داخل backend. معاينة Excel على Windows: `tests/Support/review-blood-bank-excel.ps1` تفتح الملفات الاصطناعية للقراءة فقط باستخدام Excel المحلي مع تعطيل الماكرو والروابط، ثم تصدر معاينات الطباعة. `node tests/render-blood-bank-reports.mjs` داخل frontend يحوّل PDF ومعاينات Excel إلى لقطات محلية؛ يتطلب أدوات PDF QA المحلية الاختيارية (`pdfjs-dist` و`@napi-rs/canvas` تحت `.superdesign/pdf-tools/`) ولا يضيف اعتمادًا للتطبيق.

في هذه البيئة أعاد Chrome المثبت محليًا 204 فارغة لبعض تنزيلات PDF رغم أن Laravel أعاد 200. نجح الطلب نفسه بكامل المحتوى في Chromium Headless Shell 153.0.8010.12 المعزول وفي Node؛ لم نغير المسارات أو نعطل حماية الجهاز لتجاوز ذلك. المكوّن المحلي الذي يعترض Chrome لم يُحدد. الاختبار الافتراضي للتقارير يستخدم Chromium الخاص بـPlaywright، ويمكن اختيار متصفح آخر عبر `PLAYWRIGHT_CHANNEL`.

## نتائج التنفيذ في 2026-09-14

| الفحص الفعلي | النتيجة |
|---|---|
| MySQL 8.4.7، `hospital_testing` على `127.0.0.1:13306`، driver mysql | حاجز الأمان ناجح؛ 38 اختبارًا / 3353 تحققًا ناجحة في مجموعات BloodBank والتقارير وSyrianCities المذكورة أعلاه |
| MariaDB 10.11.18، `blood_bank_cities_testing` على `127.0.0.1:13416`، driver mysql | حاجز الأمان ناجح؛ 23 اختبار تقارير/مدن/DirectoryReport ناجحًا بعد آخر تعديل؛ سبقها نجاح 20 اختبارًا للملفات والتقارير وOpenAPI والمدن، واختبار الاستكمال، واختبار التزامن/rollback في تشغيلات منفصلة |
| استكمال الترحيل الجزئي على MariaDB | أُعيد إنتاج خطأ DROP CHECK 1064 قبل الإصلاح، ثم استؤنف نفس الترحيل الجزئي بنجاح دون إعادة إنشاء الأعمدة؛ تحقق أيضًا من رفض القيد اليدوي غير المعروف وحفظ العناوين |
| SyrianCitiesSeeder مرتان على كل محرك | 14 → 183 سجلًا؛ الثانية 183 → 183 بلا إضافة أو تغيير معرّفات، والتعارض المصطنع يوقف المعاملة |
| Next → Laravel → MySQL، `blood-bank-reports-live.test.mjs` | 3 ناجحة: مسارات PDF/XLSX الثمانية، الصلاحيات، التأخير والفشل وإعادة المحاولة، التنزيل الفردي واللقطات |
| Next → Laravel → MySQL، `blood-bank-live.test.mjs` | 14 ناجحة: المسودات والعناوين والفحوص وإضافة الطبيب والتعارض والأكواد المؤرخة وعزل المنشأة |
| TypeScript / lint / production build | ناجحة؛ Next 16.3.4 standalone جديد مع التحويلات النهائية. ESLint للملفات المتغيرة ناجح بعد تعديل انتظار التقاط الجدول |
| Pint / git diff --check | ناجحان |
| إعادة قراءة XLSX | 4 ملفات / 8 أوراق؛ 32 ملفًا في القائمة، الأكواد والهواتف نصوص، تاريخ التبرع رقمي والكمية 1.25، لا صيغ من النصوص ولا ارتفاع يتجاوز 409pt |
| المعاينة | PDF.js لصفحات PDF، وMicrosoft Excel 16.0 build 20326 لمعاينة طباعة XLSX بخط Cairo؛ لقطات الواجهة 390 و768 و1440px. لا قص أو تداخل ظاهر في العينات المراجعة |

عينات PDF: القائمة 4 صفحات، المتبرع والمستفيد صفحتان لكل منهما، والتبرع صفحة واحدة. معاينات Excel: 6 و4 و3 و2 صفحات على الترتيب. الملفات واللقطات وحزمة `blood-bank-review.zip` محلية تحت `frontend/.superdesign/` ومتجاهلة في Git؛ ملف `blood-bank-reports/review.md` يفهرسها. لم تُجرَ مراجعة بيانات إنتاج أو تجربة نشر. تعذر التنزيل المباشر من بعض المصادر الجغرافية كما وُثق في دليل المدن؛ اعتمدت المطابقة على النسخة المنسوبة والأطلس المتاحين.
