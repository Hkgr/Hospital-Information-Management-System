import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  async rewrites() {
    // Transport only: Laravel still authenticates every Bearer token.
    const api = (process.env.LARAVEL_API_URL || "http://127.0.0.1:8000/api").replace(/\/$/, "");
    return ["login", "user", "logout", "dashboards", "dashboards/:key", "clinics", "clinics/options/doctors", "clinics/options/specialties", "clinics/export/:format(xlsx|pdf)", "clinics/:clinic(\\d+)", "clinics/:clinic(\\d+)/doctors", "clinics/:clinic(\\d+)/deactivate", "clinics/:clinic(\\d+)/report"].map((endpoint) => ({
      source: `/hospital-api/${endpoint}`,
      destination: `${api}/${endpoint}`,
    }));
  },
};

export default nextConfig;
