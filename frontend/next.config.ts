import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  async rewrites() {
    // Transport only: Laravel still authenticates every Bearer token.
    const api = (process.env.LARAVEL_API_URL || "http://127.0.0.1:8000/api").replace(/\/$/, "");
    return ["login", "user", "logout", "dashboards", "dashboards/:key"].map((endpoint) => ({
      source: `/hospital-api/${endpoint}`,
      destination: `${api}/${endpoint}`,
    }));
  },
};

export default nextConfig;
