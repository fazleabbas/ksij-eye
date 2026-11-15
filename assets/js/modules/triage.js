'use strict';
// ─── TRIAGE ────────────────────────────────────────────────────────────────
// Vital signs, visual acuity, eye pressure, the emergency flag (shared with
// reception on the visit) and where the patient goes next. The queue and the
// draft/complete/correct workflow live in shared/clinical.js.

const TRIAGE_FORM = {
  prepared: false,

  form() {
    return document.getElementById('tr-form');
  },

  prepare(opts) {
    if (this.prepared) return;
    clPrepareSelects(this.form(), opts);
    this.prepared = true;
  },

  render(ws) {
    const reasons = CL_OPTIONS.data.emergency_reasons;
    const current = ws.visit.emergency_reason;
    clFill(this.form(), {
      ...(ws.record || {}),
      is_emergency: ws.visit.is_emergency,
      emergency_reason: reasons.includes(current) ? current : null,
    });
    document.getElementById('tr-emg-current').textContent = current && !reasons.includes(current) ? `Emergency noted at reception: ${current}` : '';
    this.toggleEmergency();
    this.bmi();
  },

  toggleEmergency() {
    const on = this.form().querySelector('[data-field="is_emergency"]').checked;
    document.getElementById('tr-emg-section').classList.toggle('active', on);
    document.getElementById('tr-emg-fields').style.display = on ? '' : 'none';
  },

  bmi() {
    const form = this.form();
    const weight = parseFloat(form.querySelector('[data-field="weight_kg"]').value);
    const height = parseFloat(form.querySelector('[data-field="height_m"]').value);
    document.getElementById('tr-bmi').value = weight > 0 && height > 0 ? (weight / (height * height)).toFixed(2) : '';
  },
};

const TRIAGE = new ClinicalStage({
  stage: 'triage',
  prefix: 'tr',
  label: 'Triage',
  global: 'TRIAGE',
  completeLabel: 'Complete & Send',
  prepare: opts => TRIAGE_FORM.prepare(opts),
  render: ws => TRIAGE_FORM.render(ws),
  collect: form => clCollect(form),
});
