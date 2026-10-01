-- Operator-reviewed artifact only. Never run by migrations/tests.
-- Creates two data-entry users with the same access as super_admin and user 1:
-- every known permission definition, every active permission on super_admin
-- and on user 1's roles, super_admin on every active facility, a copy of
-- user 1's facility and global assignments, and global_user_roles so directory
-- operations work. Does not invent a role. Does not reactivate a disabled
-- permission. Shared roles widen for anyone else already assigned them.
-- Login is by username, not email.
--   data1 / 123456  name: مدخل بيانات
--   data2 / 123456  name: مدخل بيانات
USE admin_his;
START TRANSACTION;

INSERT INTO permissions (code, name_ar, is_active)
SELECT v.code, v.name_ar, 1 FROM (
    SELECT 'users.view' AS code, 'استعراض مستخدمي المنشأة' AS name_ar
    UNION ALL SELECT 'users.create', 'إضافة مستخدم وربطه بالمنشأة'
    UNION ALL SELECT 'users.delete', 'حذف مستخدم من المنشأة'
    UNION ALL SELECT 'roles.view', 'استعراض الأدوار وصلاحياتها'
    UNION ALL SELECT 'roles.create', 'إضافة دور وتحديد صلاحياته'
    UNION ALL SELECT 'roles.update', 'تعديل اسم الدور وصلاحياته'
    UNION ALL SELECT 'dashboards.view', 'عرض الصفحة الرئيسية'
    UNION ALL SELECT 'reports.view', 'عرض تقارير المنشأة'
    UNION ALL SELECT 'reports.export', 'تصدير تقارير المنشأة'
    UNION ALL SELECT 'audit.view', 'عرض سجل حركة النظام'
    UNION ALL SELECT 'clinics.view', 'استعراض العيادات'
    UNION ALL SELECT 'clinics.create', 'إضافة عيادة'
    UNION ALL SELECT 'clinics.update', 'تعديل وتعطيل العيادات'
    UNION ALL SELECT 'clinics.delete', 'حذف عيادة غير مرتبطة'
    UNION ALL SELECT 'clinics.export', 'تصدير تقارير العيادات'
    UNION ALL SELECT 'doctors.view', 'استعراض دليل الأطباء ومؤشرات المنشأة'
    UNION ALL SELECT 'doctors.link', 'ربط الأطباء بعيادات المنشأة'
    UNION ALL SELECT 'doctors.export', 'تصدير تقارير الأطباء في المنشأة'
    UNION ALL SELECT 'doctors.directory.create', 'إضافة طبيب إلى الدليل المشترك'
    UNION ALL SELECT 'doctors.directory.update', 'تعديل وتعطيل طبيب عالميًا'
    UNION ALL SELECT 'doctors.directory.delete', 'حذف طبيب غير مرتبط من الدليل المشترك'
    UNION ALL SELECT 'catalog.view', 'استعراض الخدمات والإجراءات في المنشأة'
    UNION ALL SELECT 'catalog.export', 'تصدير دليل الخدمات والإجراءات في المنشأة'
    UNION ALL SELECT 'catalog.beneficiaries', 'استعراض أسماء المستفيدين من الخدمات والإجراءات في المنشأة'
    UNION ALL SELECT 'catalog.audit', 'استعراض سجل تغييرات الخدمات والإجراءات في المنشأة'
    UNION ALL SELECT 'catalog.directory.create', 'إضافة تعريف إلى دليل الخدمات والإجراءات المشترك'
    UNION ALL SELECT 'catalog.directory.update', 'تعديل وتعطيل واستعادة وتفعيل تعريف مشترك'
    UNION ALL SELECT 'catalog.directory.delete', 'حذف وأرشفة تعريف مشترك'
    UNION ALL SELECT 'blood_bank.view', 'استعراض ملفات بنك الدم وتبرعات المنشأة'
    UNION ALL SELECT 'blood_bank.export', 'تصدير تقارير ملفات بنك الدم ووقائع التبرع في المنشأة'
    UNION ALL SELECT 'blood_bank.create', 'إضافة ملف متبرع أو مستفيد في المنشأة'
    UNION ALL SELECT 'blood_bank.update', 'تعديل ملفات بنك الدم في المنشأة'
    UNION ALL SELECT 'blood_bank.donations.create', 'تسجيل تبرع فعلي في المنشأة'
    UNION ALL SELECT 'blood_bank.donations.update', 'تصحيح بيانات تبرع فعلي في المنشأة'
    UNION ALL SELECT 'blood_bank.benefits.create', 'تسجيل صرف مكوّن أو نقل دم فعلي وربط المرحلتين في المنشأة'
    UNION ALL SELECT 'blood_bank.benefits.update', 'تصحيح واقعة صرف أو نقل دم في المنشأة'
    UNION ALL SELECT 'blood_bank.patients.search', 'البحث في سجل مرضى المشفى المشترك للربط ببنك الدم — تفويض عالمي'
    UNION ALL SELECT 'dossiers.view', 'عرض بطاقات المرضى وزياراتها في المنشأة'
    UNION ALL SELECT 'dossiers.delete', 'حذف بطاقة المريض وكل ارتباطاتها في المنشأة'
    UNION ALL SELECT 'dossiers.create', 'تسجيل بطاقة مريض مسودة في المنشأة'
    UNION ALL SELECT 'dossiers.personal.update', 'تعديل البيانات الشخصية لبطاقة المريض في المنشأة'
    UNION ALL SELECT 'dossiers.medical.update', 'تعديل المعلومات الطبية لبطاقة المريض في المنشأة'
    UNION ALL SELECT 'dossiers.visits.create', 'تسجيل زيارات المريض في المنشأة'
    UNION ALL SELECT 'dossiers.visits.update', 'تعديل زيارة بطاقة المريض المسودة وتشخيصاتها'
    UNION ALL SELECT 'patients.search', 'البحث في سجل المرضى المشترك لبطاقات المرضى — تفويض عالمي'
    UNION ALL SELECT 'patients.create', 'إنشاء مريض في السجل المشترك — تفويض عالمي'
    UNION ALL SELECT 'patients.update', 'تعديل هوية المريض المشتركة — تفويض عالمي'
    UNION ALL SELECT 'diagnoses.create', 'إضافة تشخيص إلى الدليل المشترك — تفويض عالمي'
    UNION ALL SELECT 'dossiers.clinical.update', 'تسجيل وتصحيح الخدمات والإجراءات والوصفة والنتيجة في زيارة مسودة'
    UNION ALL SELECT 'dossiers.attachments.view', 'عرض بيانات مرفقات زيارات بطاقة المريض'
    UNION ALL SELECT 'dossiers.attachments.upload', 'رفع مرفقات زيارة مسودة'
    UNION ALL SELECT 'dossiers.attachments.download', 'تنزيل مرفقات بطاقة المريض الخاصة'
    UNION ALL SELECT 'dossiers.attachments.void', 'إلغاء مرفق زيارة مسودة مع حفظ التاريخ'
    UNION ALL SELECT 'dossiers.finalize', 'تفعيل بطاقة المريض بعد حفظ الأقسام الخمسة الأولى'
    UNION ALL SELECT 'dossiers.visits.complete', 'إكمال الزيارة المسودة بعد مراجعتها صراحة'
    UNION ALL SELECT 'dossiers.export', 'تصدير بطاقات المرضى والزيارات المصرح بها'
    UNION ALL SELECT 'medications.create', 'إضافة تعريف دواء إلى الدليل المشترك — تفويض عالمي'
    UNION ALL SELECT 'dossiers.audit', 'استعراض سجل تغييرات بطاقة المريض داخل المنشأة'
    UNION ALL SELECT 'dossiers.import.view', 'عرض دفعات استيراد بطاقات المرضى'
    UNION ALL SELECT 'dossiers.import.create', 'رفع ملف استيراد بطاقات المرضى'
    UNION ALL SELECT 'dossiers.import.validate', 'فحص ومعاينة استيراد بطاقات المرضى'
    UNION ALL SELECT 'dossiers.import.commit', 'اعتماد استيراد بطاقات المرضى'
    UNION ALL SELECT 'dossiers.import.download', 'تنزيل قالب الاستيراد وتقرير أخطائه'
    UNION ALL SELECT 'dossiers.import.cancel', 'إلغاء دفعة استيراد بطاقات المرضى'
    UNION ALL SELECT 'dossiers.assessment.update', 'تسجيل وتصحيح التقييم التشخيصي للزيارة'
    UNION ALL SELECT 'dossiers.pathology.create', 'إضافة تقرير أو حالة تشريح مرضي'
    UNION ALL SELECT 'dossiers.pathology.update', 'استكمال وتصحيح تقارير التشريح المرضي'
    UNION ALL SELECT 'dossiers.pathology.void', 'إلغاء تقرير تشريح مرضي مع حفظ تاريخه'
    UNION ALL SELECT 'dossiers.treatment.view', 'عرض الخطط العلاجية والجرعات'
    UNION ALL SELECT 'dossiers.treatment.create', 'إنشاء مسودة خطة علاجية'
    UNION ALL SELECT 'dossiers.treatment.update', 'تعديل مسودة وإضافة نسخة علاجية'
    UNION ALL SELECT 'dossiers.treatment.activate', 'تفعيل وإعادة اعتماد خطة علاجية'
    UNION ALL SELECT 'dossiers.treatment.override', 'اعتماد علاج باستثناء التشريح غير المطلوب'
    UNION ALL SELECT 'dossiers.treatment.status', 'إيقاف وإكمال وإلغاء خطة علاجية'
    UNION ALL SELECT 'dossiers.treatment.schedule', 'جدولة الجرعات وتصحيح مواعيدها'
    UNION ALL SELECT 'dossiers.treatment.administer', 'توثيق إعطاء جرعة فعلية'
    UNION ALL SELECT 'dossiers.treatment.dispense', 'توثيق صرف دواء مع الجرعة'
    UNION ALL SELECT 'dossiers.treatment.correct', 'تصحيح واقعة إعطاء أو صرف محفوظة'
    UNION ALL SELECT 'dossiers.treatment.void', 'إبطال واقعة إعطاء أو صرف بسبب صريح'
    UNION ALL SELECT 'stock.view', 'عرض المخزون والدفعات'
    UNION ALL SELECT 'stock.receive', 'استلام الأدوية وتأكيد الاستلام'
    UNION ALL SELECT 'stock.adjust', 'تعديل المخزون والإتلاف'
    UNION ALL SELECT 'stock.issue', 'صرف الأدوية من المستودع'
    UNION ALL SELECT 'stock.return', 'تسجيل مرتجعات الأدوية'
    UNION ALL SELECT 'stock.export', 'تصدير تقارير المخزون'
    UNION ALL SELECT 'stock.suppliers.manage', 'إدارة الموردين والمستودعات'
) AS v
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.code = v.code);

INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT DISTINCT r.id, p.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM roles r
CROSS JOIN permissions p
WHERE r.is_active = 1
AND p.is_active = 1
AND (
    BINARY r.code = BINARY 'super_admin'
    OR EXISTS (SELECT 1 FROM facility_user_roles a WHERE a.user_id = 1 AND a.role_id = r.id)
    OR EXISTS (SELECT 1 FROM global_user_roles g WHERE g.user_id = 1 AND g.role_id = r.id)
)
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
);

INSERT INTO users (username, name, password, must_change_password, is_active, created_at, updated_at)
SELECT 'data1', 'مدخل بيانات', '$2y$12$H6OxJV7GJW8HlVpV8cUL3.3MSxdyCU/Y7jdwtcPtrqOmT1Gk.dpHq', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.username = 'data1');

INSERT INTO users (username, name, password, must_change_password, is_active, created_at, updated_at)
SELECT 'data2', 'مدخل بيانات', '$2y$12$EnmAnzbk3wqDtzzWo8xnvekU42ZtSPpeAtgn8BvL6NDbecXOEfUOa', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.username = 'data2');

UPDATE users
SET name = 'مدخل بيانات',
    password = '$2y$12$H6OxJV7GJW8HlVpV8cUL3.3MSxdyCU/Y7jdwtcPtrqOmT1Gk.dpHq',
    must_change_password = 0,
    is_active = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE username = 'data1';

UPDATE users
SET name = 'مدخل بيانات',
    password = '$2y$12$EnmAnzbk3wqDtzzWo8xnvekU42ZtSPpeAtgn8BvL6NDbecXOEfUOa',
    must_change_password = 0,
    is_active = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE username = 'data2';

INSERT INTO facility_user_roles (facility_id, user_id, role_id, created_at, updated_at)
SELECT f.id, u.id, r.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM users u
CROSS JOIN facilities f
CROSS JOIN roles r
WHERE u.username IN ('data1', 'data2')
AND f.is_active = 1
AND BINARY r.code = BINARY 'super_admin'
AND r.is_active = 1
AND NOT EXISTS (
    SELECT 1 FROM facility_user_roles a
    WHERE a.facility_id = f.id AND a.user_id = u.id AND a.role_id = r.id
);

INSERT INTO facility_user_roles (facility_id, user_id, role_id, created_at, updated_at)
SELECT src.facility_id, u.id, src.role_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM users u
JOIN facility_user_roles src ON src.user_id = 1
JOIN roles r ON r.id = src.role_id AND r.is_active = 1
JOIN facilities f ON f.id = src.facility_id AND f.is_active = 1
WHERE u.username IN ('data1', 'data2')
AND NOT EXISTS (
    SELECT 1 FROM facility_user_roles a
    WHERE a.facility_id = src.facility_id AND a.user_id = u.id AND a.role_id = src.role_id
);

INSERT INTO global_user_roles (user_id, role_id, created_at, updated_at)
SELECT DISTINCT u.id, r.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM users u
CROSS JOIN roles r
WHERE u.username IN ('data1', 'data2')
AND r.is_active = 1
AND (
    BINARY r.code = BINARY 'super_admin'
    OR EXISTS (SELECT 1 FROM facility_user_roles a WHERE a.user_id = 1 AND a.role_id = r.id)
    OR EXISTS (SELECT 1 FROM global_user_roles g WHERE g.user_id = 1 AND g.role_id = r.id)
    OR EXISTS (SELECT 1 FROM facility_user_roles a WHERE a.user_id = u.id AND a.role_id = r.id)
)
AND NOT EXISTS (
    SELECT 1 FROM global_user_roles g
    WHERE g.user_id = u.id AND g.role_id = r.id
);

COMMIT;

-- Verification: empty user/assignment rows require investigation.
-- Never invent a user or a role from a zero result.
SELECT id, username, name, is_active, must_change_password
FROM users
WHERE username IN ('data1', 'data2')
ORDER BY username;

SELECT u.username, f.code AS facility_code, r.code AS role_code, r.is_active AS role_active
FROM users u
JOIN facility_user_roles a ON a.user_id = u.id
JOIN facilities f ON f.id = a.facility_id
JOIN roles r ON r.id = a.role_id
WHERE u.username IN ('data1', 'data2')
ORDER BY u.username, f.code, r.code;

SELECT u.username, r.code AS role_code, r.is_active AS role_active
FROM users u
JOIN global_user_roles g ON g.user_id = u.id
JOIN roles r ON r.id = g.role_id
WHERE u.username IN ('data1', 'data2')
ORDER BY u.username, r.code;

SELECT u.username,
    (SELECT COUNT(*) FROM permissions WHERE is_active = 1) AS active_permissions,
    COUNT(DISTINCT p.id) AS granted
FROM users u
JOIN (
    SELECT a.user_id, a.role_id FROM facility_user_roles a
    UNION
    SELECT g.user_id, g.role_id FROM global_user_roles g
) AS ur ON ur.user_id = u.id
JOIN role_permissions rp ON rp.role_id = ur.role_id
JOIN permissions p ON p.id = rp.permission_id AND p.is_active = 1
WHERE u.username IN ('data1', 'data2')
GROUP BY u.id, u.username
ORDER BY u.username;

SELECT u.username, p.code
FROM users u
JOIN global_user_roles g ON g.user_id = u.id
JOIN roles r ON r.id = g.role_id AND r.is_active = 1
JOIN role_permissions rp ON rp.role_id = r.id
JOIN permissions p ON p.id = rp.permission_id AND p.is_active = 1
WHERE u.username IN ('data1', 'data2')
AND p.code IN (
    'patients.search', 'patients.create', 'patients.update',
    'diagnoses.create', 'medications.create',
    'doctors.directory.create', 'doctors.directory.update', 'doctors.directory.delete',
    'catalog.directory.create', 'catalog.directory.update', 'catalog.directory.delete',
    'blood_bank.patients.search'
)
ORDER BY u.username, p.code;
