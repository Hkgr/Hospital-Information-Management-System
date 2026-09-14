-- Reviewed deployment artifact ONLY. This file is never executed by a seeder or test runner.
-- The operator must verify target/backup and the intended role before running it.
-- No account, role, global assignment, or facility assignment is created here.
USE admin_his;
START TRANSACTION;
INSERT INTO permissions (code, name_ar, is_active)
SELECT 'blood_bank.benefits.create', 'تسجيل صرف مكوّن أو نقل دم فعلي وربط المرحلتين في المنشأة', 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'blood_bank.benefits.create');
INSERT INTO permissions (code, name_ar, is_active)
SELECT 'blood_bank.benefits.update', 'تصحيح واقعة صرف أو نقل دم في المنشأة', 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'blood_bank.benefits.update');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE BINARY r.code = BINARY 'super_admin' AND r.is_active = 1 AND p.is_active = 1
AND p.code IN ('blood_bank.benefits.create', 'blood_bank.benefits.update')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
COMMIT;
SELECT r.code AS role_code, p.code AS permission_code
FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
WHERE BINARY r.code = BINARY 'super_admin' AND p.code IN ('blood_bank.benefits.create', 'blood_bank.benefits.update');
