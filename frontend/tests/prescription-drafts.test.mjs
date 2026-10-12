import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const source = readFileSync(process.env.CLINICAL_SOURCE || new URL('../src/features/dossiers/clinical.ts', import.meta.url), 'utf8');
const code = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const clinical = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const rx = (id, kind = 'dose_linked') => ({ id, lock_version: 1, kind, prescribing_clinic_id: 1, prescribing_staff_id: 2, clinic_name: 'Clinic', doctor_name: 'Doctor', prescribed_on: '2001-03-02', funding_source_id: id, note: `Invoice ${id}`, items: [{ id: id * 10, lock_version: 1, medication_id: id, name_ar: `Medication ${id}`, code: `0${id}`, note: null, display_order: 0 }] });
const snapshot = rows => ({ clinical: { services: [], procedures: [], prescriptions: rows, outcome: null } });

test('loading three same-kind invoices preserves every ID, item and independent funding', () => {
  const draft = clinical.clinicalDraft(snapshot([rx(1), rx(2), rx(3)]));
  const rows=Array.isArray(draft.prescriptions.dose_linked)?draft.prescriptions.dose_linked:[draft.prescriptions.dose_linked];
  assert.deepEqual(rows.map(p => p.id), [1, 2, 3]);
  assert.deepEqual(draft.prescriptions.dose_linked.map(p => p.items[0].id), [10, 20, 30]);
  assert.deepEqual(draft.prescriptions.dose_linked.map(p => p.funding_source_id), [1, 2, 3]);
});
test('saving one invoice preserves other invoice and tab drafts and sends only the selected invoice', () => {
  const before = snapshot([rx(1), rx(2), rx(3), rx(4, 'outside')]);
  const d = clinical.clinicalDraft(before);
  d.selectedPrescription = '2';
  d.prescriptions.dose_linked[0].note = 'Unsaved first invoice';
  d.prescriptions.outside[0].note = 'Unsaved other tab';
  d.prescriptions.dose_linked[1].note = 'Saved second invoice';
  const payload = clinical.clinicalPayload(d, 4, 'dose_linked');
  assert.equal(payload.prescription.id, 2);
  assert.equal(payload.prescription.note, 'Saved second invoice');
  const after = snapshot([rx(1), {...rx(2), note: 'Saved second invoice', lock_version: 2}, rx(3), rx(4, 'outside')]);
  const accepted = clinical.acceptPrescriptionSave(d, before, after, 'dose_linked');
  assert.equal(accepted.prescriptions.dose_linked[0].note, 'Unsaved first invoice');
  assert.equal(accepted.prescriptions.dose_linked[1].lock_version, 2);
  assert.equal(accepted.prescriptions.outside[0].note, 'Unsaved other tab');
  assert.equal(clinical.hasUnsavedMedicationDraft(accepted, after), true);
});
test('a new invoice receives its own saved ID without replacing another new draft; legacy singular response works', () => {
  const before = snapshot([rx(1)]);
  const d = clinical.clinicalDraft(before);
  for (const key of ['new-a', 'new-b']) d.prescriptions.dose_linked.push({...d.prescriptions.dose_linked[0], id: undefined, lock_version: undefined, key, note: key});
  d.selectedPrescription = 'new-a';
  const after = snapshot([rx(1), {...rx(2), note: 'new-a'}]);
  const accepted = clinical.acceptPrescriptionSave(d, before, after, 'dose_linked');
  assert.deepEqual(accepted.prescriptions.dose_linked.map(p => p.id), [1, 2, undefined]);
  assert.equal(accepted.prescriptions.dose_linked[2].key, 'new-b');
  assert.equal(clinical.selectedPrescription(accepted, 'dose_linked').id, 2);
  const old = clinical.clinicalDraft({clinical: {...before.clinical, prescriptions: undefined, prescription: rx(9, 'unlinked')}});
  assert.equal(old.prescriptions.unlinked[0].id, 9);
});
