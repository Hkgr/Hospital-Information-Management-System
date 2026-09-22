"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useRef, useState } from "react";
import { LuPlus, LuHospital } from "react-icons/lu";
import { apiRequest, AuthError } from "@/features/auth/api";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { DirectoryBack, DirectoryTable } from "../directory/DirectoryPrimitives";
import { directoryFacility } from "../directory/facilityContext";
import Modal from "../clinics/Modal";
import { type Capabilities, type Options, type Page, type Receipt, receiptStatus, useStockRequest } from "./api";
import styles from "../clinics/clinics.module.css";

export default function ReceiptsScreen({ receiptId }: { receiptId?: string }) {
  const { access } = useIdentity();
  const params = useSearchParams();
  const { entry, facilityId } = directoryFacility(access, "stock.view", params.get("facility_id"));
  if (!entry) return <section className={styles.status}><h2>المخزون غير متاح</h2><p role="alert">تعذّر تحديد مشفى متاح لك في المخزون.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  return <Workspace key={`${facilityId}:${receiptId ?? ""}`} facilityId={facilityId!} name={entry.facility.name_ar} receiptId={receiptId} />;
}

function Workspace({ facilityId, name, receiptId }: { facilityId: number; name: string; receiptId?: string }) {
  const router = useRouter(); const params = useSearchParams();
  const [revision, setRevision] = useState(0);
  const [creating, setCreating] = useState(false);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  if (params.get("status")) query.set("status", params.get("status")!);
  const list = useStockRequest<Page<Receipt>>(receiptId ? null : `stock/receipts?${query}`, true, true, revision);
  const detail = useStockRequest<{ data: Receipt; capabilities: Capabilities }>(receiptId ? `stock/receipts/${receiptId}?facility_id=${facilityId}` : null, true, false, revision);
  const options = useStockRequest<Options>(`stock/options?facility_id=${facilityId}`);
  const caps = (receiptId ? detail.data?.capabilities : list.data?.capabilities);
  const receipt = detail.data?.data;
  const [confirmError, setConfirmError] = useState("");
  async function confirm() {
    if (!receipt || receipt.status !== "draft") return;
    setConfirmError("");
    try {
      await apiRequest(`stock/receipts/${receipt.id}/confirm`, { method: "POST", body: JSON.stringify({ facility_id: facilityId, lock_version: receipt.lock_version }) });
      setRevision(v => v + 1);
    } catch (reason) { setConfirmError(reason instanceof AuthError ? reason.message : "تعذّر تأكيد الإذن."); }
  }
  if (receiptId) {
    return <div className={styles.screen}>
      <div className={styles.context}><LuHospital aria-hidden="true" /><span>سياق المنشأة</span><strong>{name}</strong></div>
      <DirectoryBack href={`/stock/receipts?facility_id=${facilityId}`}>أذونات الاستلام</DirectoryBack>
      {detail.error && <p role="alert">{detail.error}</p>}
      {confirmError && <p role="alert">{confirmError}</p>}
      {receipt && <>
        <header className={styles.header}><h1>إذن {receipt.receipt_no}</h1><p>{receiptStatus(receipt.status)} · {receipt.store_name_ar} · {receipt.funding_name_ar}</p>
          {caps?.receive && receipt.status === "draft" && <button className={styles.primary} onClick={() => void confirm()}>تأكيد الاستلام</button>}</header>
        <DirectoryTable label="بنود الإذن" headers={["الدواء", "الدفعة", "الانتهاء", "الكمية", "هدية"]}>
          {(receipt.items ?? []).map(item => <tr key={item.id}><td>{item.medication_name_ar}</td><td>{item.batch_number}</td><td>{item.expiry_date}</td><td>{item.quantity}</td><td>{item.free_quantity}</td></tr>)}
        </DirectoryTable>
      </>}
    </div>;
  }
  return <div className={styles.screen}>
    <div className={styles.context}><LuHospital aria-hidden="true" /><span>سياق المنشأة</span><strong>{name}</strong></div>
    <header className={styles.header}><h1>أذونات الاستلام</h1>
      <nav className={styles.actions}>
        <Link className={styles.secondary} href={`/stock/suppliers?facility_id=${facilityId}`}>الموردون</Link>
        <Link className={styles.secondary} href={`/stock/stores?facility_id=${facilityId}`}>المستودعات</Link>
        {caps?.receive && <button className={styles.primary} onClick={() => setCreating(true)}><LuPlus aria-hidden="true" />إذن جديد</button>}
      </nav>
    </header>
    {list.error && <p role="alert">{list.error}</p>}
    <DirectoryTable label="أذونات الاستلام" headers={["الرقم", "المستودع", "التاريخ", "الحالة"]}>
      {(list.data?.data ?? []).map(row => <tr key={row.id}><td><Link href={`/stock/receipts/${row.id}?facility_id=${facilityId}`}>{row.receipt_no}</Link></td><td>{row.store_name_ar}</td><td>{row.received_on}</td><td>{receiptStatus(row.status)}</td></tr>)}
    </DirectoryTable>
    {creating && options.data && <ReceiptEditor facilityId={facilityId} options={options.data} onClose={() => setCreating(false)} onSaved={row => { setCreating(false); setRevision(v => v + 1); router.push(`/stock/receipts/${row.id}?facility_id=${facilityId}`); }} />}
  </div>;
}

function ReceiptEditor({ facilityId, options, onClose, onSaved }: { facilityId: number; options: Options; onClose: () => void; onSaved: (row: Receipt) => void }) {
  const [receiptNo, setReceiptNo] = useState("");
  const [storeId, setStoreId] = useState(String(options.stores[0]?.id ?? ""));
  const [supplierId, setSupplierId] = useState(options.suppliers[0] ? String(options.suppliers[0].id) : "");
  const [source, setSource] = useState(options.medication_sources[0]?.code ?? "ministry_of_health");
  const [receivedOn, setReceivedOn] = useState(new Date().toISOString().slice(0, 10));
  const [medicationId, setMedicationId] = useState(String(options.medications[0]?.id ?? ""));
  const [batch, setBatch] = useState("");
  const [expiry, setExpiry] = useState("");
  const [quantity, setQuantity] = useState("1");
  const [free, setFree] = useState("0");
  const [error, setError] = useState<AuthError | null>(null);
  const [busy, setBusy] = useState(false);
  const request = useRef<{ body: string; id: string } | null>(null);
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (busy) return;
    const payload = { facility_id: facilityId, store_id: Number(storeId), receipt_no: receiptNo, supplier_id: supplierId ? Number(supplierId) : null, medication_source: source, received_on: receivedOn,
      items: medicationId && batch && expiry ? [{ medication_id: Number(medicationId), batch_number: batch, expiry_date: expiry, quantity: Number(quantity), free_quantity: Number(free) }] : [] };
    const body = JSON.stringify(payload);
    if (request.current?.body !== body) request.current = { body, id: crypto.randomUUID() };
    setBusy(true); setError(null);
    try {
      const row = await apiRequest<Receipt>("stock/receipts", { method: "POST", body: JSON.stringify({ ...payload, request_id: request.current.id }) });
      onSaved(row);
    } catch (reason) { setError(reason instanceof AuthError ? reason : null); }
    finally { setBusy(false); }
  }
  return <Modal title="إذن استلام جديد" onClose={onClose}>
    <form className={styles.form} onSubmit={save}>
      {error && <p role="alert">{error.message}{error.fields.items ? ` — ${error.fields.items}` : ""}</p>}
      <label>رقم الإذن<input value={receiptNo} onChange={e => setReceiptNo(e.target.value)} required maxLength={50} /></label>
      <label>المستودع<select value={storeId} onChange={e => setStoreId(e.target.value)} required>{options.stores.map(row => <option key={row.id} value={row.id}>{row.name_ar}</option>)}</select></label>
      <label>المورد<select value={supplierId} onChange={e => setSupplierId(e.target.value)}><option value="">بدون مورد</option>{options.suppliers.map(row => <option key={row.id} value={row.id}>{row.name_ar}</option>)}</select></label>
      <label>جهة التمويل<select value={source} onChange={e => setSource(e.target.value)} required>{options.medication_sources.map(row => <option key={row.code} value={row.code}>{row.name_ar}</option>)}</select></label>
      <label>تاريخ الاستلام<input type="date" value={receivedOn} onChange={e => setReceivedOn(e.target.value)} required /></label>
      <label>الدواء<select value={medicationId} onChange={e => setMedicationId(e.target.value)}>{options.medications.map(row => <option key={row.id} value={row.id}>{row.name_ar}</option>)}</select></label>
      <label>رقم الدفعة<input value={batch} onChange={e => setBatch(e.target.value)} required maxLength={60} /></label>
      <label>تاريخ الانتهاء<input type="date" value={expiry} onChange={e => setExpiry(e.target.value)} required /></label>
      <label>الكمية<input type="number" min="0.0001" step="0.0001" value={quantity} onChange={e => setQuantity(e.target.value)} required /></label>
      <label>كمية مجانية<input type="number" min="0" step="0.0001" value={free} onChange={e => setFree(e.target.value)} /></label>
      <div className={styles.actions}><button type="button" className={styles.secondary} onClick={onClose}>إلغاء</button><button className={styles.primary} disabled={busy}>حفظ المسودة</button></div>
    </form>
  </Modal>;
}
