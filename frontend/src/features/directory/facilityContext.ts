import type { Identity } from "../auth/api";

// Preserve the identity's server ordering. An explicit inaccessible ID never falls back.
export function directoryFacility(access: Identity["access"], permission: string, requested: string | null) {
  const allowed = access.filter(entry => entry.permissions.includes(permission));
  const facilityId = requested === null ? allowed[0]?.facility.id : Number(requested);
  return { allowed, facilityId, entry: allowed.find(entry => entry.facility.id === facilityId) };
}
