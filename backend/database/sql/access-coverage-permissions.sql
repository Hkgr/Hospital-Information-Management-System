-- Operator-reviewed artifact only. Never run by migrations/tests. No users,
-- roles, global access or facility assignments are created or expanded here.
USE admin_his;
START TRANSACTION;
INSERT INTO permissions (code, name_ar, is_active)
SELECT v.code, v.name_ar, 1 FROM (
    SELECT 'dashboards.view' AS code, 'عرض الصفحة الرئيسية' AS name_ar
    UNION ALL SELECT 'reports.view', 'عرض تقارير المنشأة'
    UNION ALL SELECT 'reports.export', 'تصدير تقارير المنشأة'
    UNION ALL SELECT 'audit.view', 'عرض سجل حركة النظام'
    UNION ALL SELECT 'roles.view', 'استعراض الأدوار وصلاحياتها'
    UNION ALL SELECT 'roles.create', 'إضافة دور وتحديد صلاحياته'
    UNION ALL SELECT 'roles.update', 'تعديل اسم الدور وصلاحياته'
) AS v
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.code = v.code);
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE BINARY r.code = BINARY 'super_admin' AND r.is_active = 1
AND p.code IN ('dashboards.view', 'reports.view', 'reports.export', 'audit.view', 'roles.view', 'roles.create', 'roles.update')
AND p.is_active = 1
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
COMMIT;
-- Verification: zero rows requires investigation, never an automatic role creation.
SELECT r.code AS role_code, r.is_active AS role_active, p.code, p.name_ar, p.is_active AS permission_active
FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
WHERE BINARY r.code = BINARY 'super_admin'
AND p.code IN ('dashboards.view', 'reports.view', 'reports.export', 'audit.view', 'roles.view', 'roles.create', 'roles.update')
ORDER BY p.code;
