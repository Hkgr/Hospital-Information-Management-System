-- Operator-reviewed only: select the approved database explicitly.
-- Run OncologyPermissionsSeeder to define codes first. This script creates
-- no users, roles, facility memberships or global privileges.
START TRANSACTION;
INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM roles r CROSS JOIN permissions p
WHERE r.code = 'super_admin' AND r.is_active = 1 AND p.is_active = 1
AND p.code IN ('dossiers.view', 'dossiers.treatment.view', 'dossiers.treatment.create',
 'dossiers.treatment.update', 'dossiers.treatment.activate', 'dossiers.treatment.override',
 'dossiers.treatment.status', 'dossiers.treatment.schedule', 'dossiers.treatment.administer',
 'dossiers.treatment.dispense', 'dossiers.treatment.correct', 'dossiers.treatment.void')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
COMMIT;
SELECT r.code, p.code, p.is_active FROM roles r
JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id
WHERE r.code='super_admin' AND p.code LIKE 'dossiers.treatment.%' ORDER BY p.code;
-- Audit, export, visit creation, attachment and pathology permissions remain
-- independently operator-reviewed; this script does not assign them.
