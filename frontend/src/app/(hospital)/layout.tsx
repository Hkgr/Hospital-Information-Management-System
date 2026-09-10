import AuthenticatedLayout from "@/features/auth/AuthenticatedLayout";

export default function HospitalLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <AuthenticatedLayout>{children}</AuthenticatedLayout>;
}
