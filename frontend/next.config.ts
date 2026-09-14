import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  async rewrites() {
    // Transport only: Laravel still authenticates every Bearer token.
    const api = (process.env.LARAVEL_API_URL || "http://127.0.0.1:8000/api").replace(/\/$/, "");
    return [
      "login", "user", "logout", "dashboards", "dashboards/:key",
      "blood-bank", "blood-bank/options", "blood-bank/cities", "blood-bank/clinics", "blood-bank/doctors", "blood-bank/patients",
      "blood-bank/export/:format(pdf|xlsx)", "blood-bank/:kind(donor|recipient)/:item(\\d+)/report/:format(pdf|xlsx)",
      "blood-bank/donor/:donor(\\d+)/donations/:donation(\\d+)/report/:format(pdf|xlsx)",
      "blood-bank/patients/:patient(\\d+)", "blood-bank/:kind(donor|recipient)/:item(\\d+)",
      "blood-bank/donor/:donor(\\d+)/donations", "blood-bank/donor/:donor(\\d+)/donations/:donation(\\d+)",
      "service-catalog", "service-catalog/options", "service-catalog/classifications", "service-catalog/export/:format(xlsx|pdf)",
      "service-catalog/context", "service-catalog/categories", "service-catalog/:kind(service|procedure)/:item(\\d+)/events",
      "service-catalog/:kind(service|procedure)/:item(\\d+)", "service-catalog/:kind(service|procedure)/:item(\\d+)/deletion-preview",
      "service-catalog/:kind(service|procedure)/:item(\\d+)/beneficiaries", "service-catalog/:kind(service|procedure)/:item(\\d+)/history",
      "service-catalog/:kind(service|procedure)/:item(\\d+)/report", "service-catalog/:kind(service|procedure)/:item(\\d+)/archive",
      "service-catalog/:kind(service|procedure)/:item(\\d+)/restore", "service-catalog/:kind(service|procedure)/:item(\\d+)/deactivate",
      "service-catalog/:kind(service|procedure)/:item(\\d+)/reactivate",
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
