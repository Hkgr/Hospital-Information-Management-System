-- Operator-reviewed only. Select the approved database explicitly first.
-- Defines no users, roles or facility assignments; run the definition seeder first.
START TRANSACTION;
INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM roles r CROSS JOIN permissions p
WHERE r.code = 'super_admin' AND r.is_active = 1 AND p.is_active = 1
AND p.code IN ('dossiers.view', 'dossiers.assessment.update', 'dossiers.pathology.create', 'dossiers.pathology.update', 'dossiers.pathology.void')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
COMMIT;
SELECT r.code, p.code, p.is_active
FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
WHERE r.code = 'super_admin' AND p.code IN ('dossiers.view', 'dossiers.assessment.update', 'dossiers.pathology.create', 'dossiers.pathology.update', 'dossiers.pathology.void') ORDER BY p.code;
-- Attachment permissions remain independent and are not granted by this script.
