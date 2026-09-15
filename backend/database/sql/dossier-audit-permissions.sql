-- Operator-reviewed only: select the approved database in your SQL client.
-- Run DossierAuditPermissionsSeeder first. No users, roles or assignments created.
-- Both permissions are facility-scoped through existing facility_user_roles only.
START TRANSACTION;
INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM roles r CROSS JOIN permissions p
WHERE r.code = 'super_admin' AND r.is_active = 1 AND p.is_active = 1
AND p.code IN ('dossiers.view', 'dossiers.audit')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
COMMIT;
SELECT r.code AS role_code, p.code AS permission_code, p.is_active
FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
WHERE r.code = 'super_admin' AND p.code IN ('dossiers.view', 'dossiers.audit') ORDER BY p.code;
-- Do not create global or facility assignments here; inspect the existing scopes.
SELECT fur.user_id, f.code AS facility_code, r.code AS role_code
FROM facility_user_roles fur JOIN facilities f ON f.id = fur.facility_id JOIN roles r ON r.id = fur.role_id
WHERE r.code = 'super_admin' AND r.is_active = 1 ORDER BY fur.user_id, f.code;
