import { redirect } from "next/navigation";

// Keep bookmarks and explicit (including refused) facility context.
export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(await searchParams)) {
    for (const part of Array.isArray(value) ? value : value === undefined ? [] : [value]) query.append(key, part);
  }
  query.set("view", "registration");
  redirect(`/patient-cards?${query}`);
}
