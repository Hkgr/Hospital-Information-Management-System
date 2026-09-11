import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  async rewrites() {
    // Transport only: Laravel still authenticates every Bearer token.
    const api = (process.env.LARAVEL_API_URL || "http://127.0.0.1:8000/api").replace(/\/$/, "");
    return [
      "login", "user", "logout", "dashboards", "dashboards/:key",
      "clinics", "clinics/options/doctors", "clinics/options/specialties", "clinics/export/:format(xlsx|pdf)",
      "clinics/:clinic(\\d+)", "clinics/:clinic(\\d+)/doctors", "clinics/:clinic(\\d+)/deactivate", "clinics/:clinic(\\d+)/report",
      "clinics/:clinic(\\d+)/deletion-preview", "clinics/:clinic(\\d+)/link-history",
      "clinics/:clinic(\\d+)/archive", "clinics/:clinic(\\d+)/restore", "clinics/:clinic(\\d+)/reactivate",
      "doctors", "doctors/options", "doctors/options/clinics", "doctors/export/:format(xlsx|pdf)",
      "doctors/:doctor(\\d+)", "doctors/:doctor(\\d+)/clinics", "doctors/:doctor(\\d+)/deactivate", "doctors/:doctor(\\d+)/report",
      "doctors/:doctor(\\d+)/deletion-preview", "doctors/:doctor(\\d+)/link-history",
      "doctors/:doctor(\\d+)/archive", "doctors/:doctor(\\d+)/restore", "doctors/:doctor(\\d+)/reactivate",
    ].map((endpoint) => ({
      source: `/hospital-api/${endpoint}`,
      destination: `${api}/${endpoint}`,
    }));
  },
};

export default nextConfig;
