"use client";
import EventScreen from "./EventScreen";
import { type Kind } from "./api";
export default function BloodBankScreen({ kind, id, donationId }: { kind?: Kind; id?: string; donationId?: string }) {
  return <EventScreen legacy={kind && id ? { kind, id, donationId } : undefined} />;
}
