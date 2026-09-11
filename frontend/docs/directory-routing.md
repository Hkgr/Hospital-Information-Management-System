# التحقق من تحويلات دورة حياة الدليل

كان Laravel يملك مسارات دورة الحياة، لكن قائمة `next.config.ts` لم تتضمنها؛ لذلك أعاد Next استجابة 404 قبل وصول الطلب إلى Laravel. أضيفت تحويلات محددة لكل من `doctors/:doctor(\\d+)` و`clinics/:clinic(\\d+)`:

- `deletion-preview` و`link-history` لطلبات GET.
- `archive` و`restore` و`reactivate` لطلبات POST.

بقي عنوان الوجهة من `LARAVEL_API_URL`، وقيد المعرّف الرقمي والمسارات السابقة. لا تحويل عام ولا تعديل لمنطق Laravel أو صلاحياته. المعرّف غير الرقمي واللاحقة غير المعروفة يعيدان 404 من Next؛ المعرّف الرقمي لسجل غير موجود يصل إلى Laravel ليعيد 404 بعقد JSON، لأن معرفة وجود السجلات مسؤولية Laravel.

## تشغيل الاختبار الحي محليًا

يستخدم `tests/directory-lifecycle-routing.test.mjs` طلبات HTTP فعلية عبر Next production standalone إلى Laravel وقاعدة MySQL اختبارية. لا يستخدم محاكاة API أو `page.route`. الخادم المساعد يشغّل Laravel HTTP kernel والمسارات والحماية الحالية، ويضيف ترويسة اختبار لتمييز استجابة Laravel عن 404 الصادرة من Next. يقيّد التشغيل بالاتصال المحلي ويطبّق حاجز قاعدة الاختبار قبل كل طلب. نوع الطبيب `ROUTING_DOCTOR` معتمد في هذا الخادم الاختباري فقط.

المتطلبات: الاعتمادات الحالية مثبتة، وقاعدة MySQL اختبارية موجودة بمخطط الفرع الحالي. اضبط `backend/.env.testing` محليًا وفق حاجز `TestDatabaseSafety` دون إيداعه في Git. لا تشغّل الاختبار مع إعدادات قاعدة التطوير أو الإنتاج، ولا تستخدم SQLite. المساعد لا ينشئ قاعدة ولا يشغّل migrations ولا يمسحها.

الأوامر التالية لـPowerShell، في جلسات منفصلة. من `backend`، يجب نجاح الحاجز أولًا:

```powershell
php artisan test-db:check --connect --env=testing
$env:APP_ENV='testing'
php -S 127.0.0.1:8005 tests/Support/directory-routing-server.php
```

من `frontend`، ابنِ **بعد ضبط عنوان Laravel**؛ تعديلات rewrites تحتاج بناءً جديدًا، وليس تغيير متغير البيئة وقت التشغيل فقط. أوقف أي standalone سابق قبل إعادة البناء:

```powershell
$env:LARAVEL_API_URL='http://127.0.0.1:8005/api'
npm.cmd run build
Copy-Item -LiteralPath public -Destination .next/standalone -Recurse -Force
Copy-Item -LiteralPath .next/static -Destination .next/standalone/.next -Recurse -Force
$env:PORT='3105'
$env:HOSTNAME='127.0.0.1'
node .next/standalone/server.js
```

من جلسة أخرى داخل `frontend`:

```powershell
$env:TEST_BASE_URL='http://127.0.0.1:3105'
npm.cmd run test:directory-routing
```

ينشئ الاختبار منشأة ومستخدمين ودورًا وسجلات اصطناعية بمعرّفات عشوائية لكل تشغيل، مع Sanctum tokens فعلية. تحفظ بيانات التشغيل مؤقتًا داخل `backend/storage/framework/testing` المهمل في Git؛ لا تُطبع التوكنات. بعد التحقق تُلغى توكنات هذا التشغيل ويُحذف ملفها، وتبقى السجلات الاصطناعية وتاريخها في القاعدة الاختبارية. يُرفض تشغيل متزامن إذا وُجد ملف تشغيل سابق؛ إذا قوطع التشغيل، أوقف الاختبار أولًا ثم نفّذ من `backend`:

```powershell
$env:APP_ENV='testing'
php tests/Support/directory-routing-fixture.php cleanup
```

## نتيجة التحقق في إصلاح PR #11

- قبل إضافة التحويلات: فشلت الحالات الـ18؛ التحويلات العشرة أعادت 404 بدل 401، وتوقفت دورتا الحذف عند `deletion-preview` بـ404. إنشاء السجلات عبر المسارات السابقة كان ناجحًا.
- بعد الإضافة وإعادة البناء: نجحت الحالات الـ18، دون تجاوز. صُحح أثناء إعداد الاختبار توقع رمز رفض الوصول ليطابق عقد Laravel الموجود: `DOCTOR_ACCESS_DENIED` / `CLINIC_ACCESS_DENIED`.
- البيئة الفعلية: Next **16.3.4** standalone على `127.0.0.1:3105`، Laravel عبر PHP **8.4.14** على `127.0.0.1:8005`، MySQL **8.4.7**، driver `mysql`، قاعدة `hospital_testing` على `127.0.0.1:13306` بعد نجاح حاجز الأمان.
- لكل وحدة: معاينة وحذف نهائي بلا مراجع، معاينة وأرشفة سجل مرتبط، تاريخ الارتباطات مع تمرير pagination في query، استعادة كغير فعال ثم تفعيل مستقل، إعادة قراءة الطرفين والعدادات والنسخ، وعدم إعادة فتح الفترة المغلقة. تحقق مستقل من MySQL يثبت الحذف الفعلي والحالات والنسخ ونهاية الفترة وأحداث التدقيق مرة واحدة.
- التوكنات وجسم JSON وquery تمر عبر Next؛ اختُبرت عقود 401 و403 و409 و422 و404، وليس HTTP status وحده. لكل تحويل اختبار مستقل يفشل عند غيابه، مع اختبار حدود المسارات غير المسموحة.

هذا تحقق HTTP حي لمسار النقل، وليس جلسة تفاعل مستخدم كاملة مع الواجهة. اختبارات الواجهة المحاكية منفصلة ولا تُعد دليلًا على مرور الطلبات إلى Laravel. لا اتصال بالإنتاج ولا نشر ضمن هذا الإجراء.
