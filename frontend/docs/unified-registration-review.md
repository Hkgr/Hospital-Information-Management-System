# مراجعة التسجيل ودليل المهام

صور من بناء Next standalone المحلي، عبر Laravel وMariaDB اختبارية فعلية. الأسماء والمشفى والحسابات بيانات تدريب اصطناعية؛ لا صور لبيانات مرضى حقيقية.

نتيجة التشغيل النهائي: 8 اختبارات ناجحة، دون فشل أو تجاوز، خلال 76.37 ثانية. لم يستخدم هذا التشغيل اعتراض الطلبات أو محاكاة API؛ اختبارات الواجهة القديمة منفصلة عنه.

اتجاه الدليل المعتمد: مخطط كبير يشرح البطاقة الواحدة والزيارات المتعددة، ثم مثال مرقّم من البحث إلى حفظ المسودة وتسليمها للاستكمال. تظهر الإجراءات والروابط بحسب صلاحيات المهمة وتفويضاتها المحلية والعالمية. الرسوم رأسية على الهاتف، وتستخدم أيقونات المشروع وخط Cairo المحلي دون أصول خارجية أثناء التشغيل.

| العرض | دليل المهام | التسجيل | إدارة الصلاحيات |
|---|---|---|---|
| 390px | [الدليل](images/unified-registration/guide-390.png) | [التسجيل](images/unified-registration/registration-390.png) | [الصلاحيات](images/unified-registration/permissions-390.png) |
| 768px | [الدليل](images/unified-registration/guide-768.png) | [التسجيل](images/unified-registration/registration-768.png) | [الصلاحيات](images/unified-registration/permissions-768.png) |
| 1440px | [الدليل](images/unified-registration/guide-1440.png) | [التسجيل](images/unified-registration/registration-1440.png) | [الصلاحيات](images/unified-registration/permissions-1440.png) |

اللقطات مع تفضيل تقليل الحركة للحصول على صور ثابتة. اختبار AppShell القائم يغطي الطي والتوسيع وحركة الإطار وإعادة التركيز، بينما يفحص التكامل الجديد حدود المحتوى وعدم التمرير الأفقي واستخدام لوحة المفاتيح في النماذج والحوارات.

تشغيل التكامل بعد بناء جديد وضبط عنوان Laravel المحلي ونسخ public و.next/static إلى standalone:

```powershell
$env:UNIFIED_TEST_URL='http://127.0.0.1:3198'
node --test tests/unified-registration-live.test.mjs
```

يجب تشغيل Laravel باستخدام `backend/tests/Support/dossier-server.php` بعد حاجز أمان قاعدة الاختبار. لا يستهدف الاختبار عنوانًا خارجيًا. تفاصيل الصلاحيات والترقية والنتائج والقيود في [دليل التفعيل](../../backend/docs/unified-patient-registration.md).
