"use client";
import { useEffect, useRef, useState } from "react";
import { apiRequest } from "../auth/api";
import DoctorEditor from "../doctors/DoctorEditor";
import { type Doctor, type Options as DoctorOptions } from "../doctors/api";
import Modal from "../clinics/Modal";
import { type Choice, type Page, useBloodRequest } from "./api";
import styles from "../clinics/clinics.module.css";

export default function InlineDoctor({ facilityId, clinic, onClose, onSelected }: { facilityId: number; clinic: Choice; onClose: () => void; onSelected: (value: Choice) => void }) {
  const options = useBloodRequest<DoctorOptions>(`doctors/options?facility_id=${facilityId}`);
  const [created, setCreated] = useState<Doctor | null>(null); const [uncertain, setUncertain] = useState(""); const [message, setMessage] = useState(""); const [busy, setBusy] = useState(false);
  const pending = useRef(false); const controller = useRef<AbortController | null>(null); useEffect(() => () => controller.current?.abort(), []);
  async function verify(doctor: Doctor, link = false) {
    if (pending.current) return; pending.current = true; setBusy(true); setMessage(""); const active = new AbortController(); controller.current = active;
    try {
      if (link) { const current = await apiRequest<Doctor>(`doctors/${doctor.id}?facility_id=${facilityId}`, { signal: active.signal }); await apiRequest(`doctors/${doctor.id}/clinics`, { method: "PUT", signal: active.signal, body: JSON.stringify({ facility_id: facilityId, lock_version: current.lock_version, clinic_add_ids: [clinic.id], clinic_remove_ids: [] }) }); }
      const response = await apiRequest<Page<Choice>>(`blood-bank/doctors?facility_id=${facilityId}&clinic_id=${clinic.id}&search=${encodeURIComponent(doctor.code)}`, { signal: active.signal }, "envelope");
      const row = response.data.find(row => row.id === doctor.id);
      if (!active.signal.aborted) { if (row) onSelected(row); else setMessage("الطبيب محفوظ، لكن ارتباطه بهذه العيادة غير متاح. يمكنك استكمال الربط دون إنشائه مرة أخرى."); }
    } catch { if (!active.signal.aborted) setMessage("الطبيب محفوظ. تعذّر التحقق من ارتباطه أو تحديث الخيارات؛ أعد المحاولة دون إنشاء طبيب آخر."); }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  async function recover() {
    if (pending.current) return; pending.current = true; setBusy(true); setMessage(""); const active = new AbortController(); controller.current = active;
    try { const response = await apiRequest<Page<Doctor>>(`doctors?facility_id=${facilityId}&search=${encodeURIComponent(uncertain)}`, { signal: active.signal }, "envelope"); const row = response.data.find(row => row.code === uncertain); if (!active.signal.aborted) { if (row) { setCreated(row); setUncertain(""); setMessage("عُثر على الطبيب بهذا الكود. راجع هويته ثم تحقق من الربط."); } else { setMessage("لم يُعثر على طبيب بهذا الكود. يمكنك العودة إلى المسودة وإعادة محاولة الحفظ."); } } }
    catch { if (!active.signal.aborted) setMessage("تعذّر التحقق. أعد المحاولة قبل إنشاء سجل آخر."); }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  if (!options.data || options.error) return <Modal title="إضافة طبيب" onClose={onClose}><p role={options.error ? "alert" : "status"}>{options.error ?? "جارٍ تحميل نموذج الطبيب…"}</p>{options.error && <button onClick={options.retry}>إعادة المحاولة</button>}</Modal>;
  if (created) return <Modal title="استكمال الطبيب المحفوظ" onClose={onClose} busy={busy}><p>{created.name} · <bdi>{created.code}</bdi></p>{message && <p role="alert">{message}</p>}<div className={styles.actions}><button className={styles.secondary} disabled={busy} onClick={() => void verify(created)}>تحديث خيارات الطبيب</button><button className={styles.primary} disabled={busy || !options.data.capabilities.link} onClick={() => void verify(created, true)}>استكمال الربط بالعيادة</button></div></Modal>;
  return <><DoctorEditor facilityId={facilityId} options={options.data} initialClinic={{ ...clinic, code: clinic.code ?? "", starts_on: null, is_linked: false, can_view: false }} onClose={onClose} onReloaded={() => {}} onUncertainCreate={setUncertain} onSaved={doctor => { setCreated(doctor); void verify(doctor); }} />{uncertain && <Modal title="التحقق من نتيجة حفظ الطبيب" onClose={() => setUncertain("")} busy={busy}><p>لم تتأكد نتيجة الحفظ للكود <bdi>{uncertain}</bdi>. تحقق أولًا كي لا تُكرر إنشاء الطبيب.</p>{message && <p role="alert">{message}</p>}<button className={styles.primary} disabled={busy} onClick={() => void recover()}>البحث عن الطبيب المحفوظ</button><button className={styles.secondary} disabled={busy} onClick={() => setUncertain("")}>العودة إلى مسودة الطبيب</button></Modal>}</>;
}
