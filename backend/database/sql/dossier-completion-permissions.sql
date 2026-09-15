-- Operator-reviewed artifact only. Run definitions seeders first.
-- No new roles, users, global assignments or facility assignments are created.
-- Select the operator-approved database explicitly in the SQL client.
-- This file intentionally does not select a database or run automatically.
START TRANSACTION;
INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM roles r CROSS JOIN permissions p
WHERE r.code = 'super_admin' AND r.is_active = 1 AND p.is_active = 1
AND p.code IN ('dossiers.view', 'dossiers.create', 'dossiers.personal.update', 'dossiers.medical.update',
 'dossiers.visits.create', 'dossiers.visits.update', 'patients.search', 'patients.create', 'patients.update', 'diagnoses.create', 'dossiers.clinical.update', 'dossiers.attachments.view', 'dossiers.attachments.upload',
 'dossiers.attachments.download', 'dossiers.attachments.void', 'dossiers.finalize',
 'dossiers.visits.complete', 'dossiers.export', 'medications.create')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
COMMIT;
SELECT r.code AS role_code, p.code AS permission_code, p.is_active
FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
WHERE r.code = 'super_admin' AND r.is_active = 1 AND
(p.code LIKE 'dossiers.%' OR p.code IN ('patients.search','patients.create','patients.update','diagnoses.create','medications.create'))
ORDER BY p.code;
-- Global operations additionally require an EXISTING global_user_roles assignment.
-- Inspect assignments; do not widen scopes as part of this script.
SELECT g.user_id, r.code FROM global_user_roles g JOIN roles r ON r.id = g.role_id WHERE r.code = 'super_admin' AND r.is_active = 1;

-- Facility grants remain limited to existing facility assignments.
SELECT fur.user_id, f.code AS facility_code, r.code AS role_code
FROM facility_user_roles fur JOIN facilities f ON f.id = fur.facility_id
JOIN roles r ON r.id = fur.role_id WHERE r.code = 'super_admin' AND r.is_active = 1
ORDER BY fur.user_id, f.code;
