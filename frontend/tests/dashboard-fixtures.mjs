// Test-only API data. Production uses Laravel and never imports this file.
export function dashboardFixture(user, facilities = []) {
  const dashboard = { key: "general", title: "لوحة التحكم", requires_facility: false, facilities, default_facility_id: null };
  return {
    catalog: { dashboards: [dashboard], default_dashboard_key: "general" },
    detail: { dashboard, user, facilities, selected_facility_id: null, links: [] },
  };
}
