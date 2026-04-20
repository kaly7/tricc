document.addEventListener('DOMContentLoaded', () => {
  const timeModeInputs = document.querySelectorAll('input[name="time_mode"]');
  const endBlock = document.querySelector('.tracker-mode-end');
  const durationBlock = document.querySelector('.tracker-mode-duration');
  const startHour = document.getElementById('start_hour');
  const startMinute = document.getElementById('start_minute');
  const endHour = document.getElementById('end_hour');
  const endMinute = document.getElementById('end_minute');
  const durationHours = document.querySelector('select[name="duration_hours"]');
  const durationMinutes = document.querySelector('select[name="duration_minutes"]');
  const breakMinutes = document.querySelector('input[name="break_minutes"]');
  const calcDuration = document.getElementById('calculated_duration');
  const calcEnd = document.getElementById('calculated_end_time');
  const vehicleSelect = document.getElementById('vehicle_id');
  const travelKmInput = document.getElementById('travel_km');
  const calcTravel = document.getElementById('calculated_travel_time');

  function pad(v) { return String(v).padStart(2, '0'); }
  function parseIntSafe(el) { return el && el.value !== '' ? parseInt(el.value, 10) : null; }
  function toMinutes(h, m) { return (h * 60) + m; }
  function human(mins) {
    if (mins === null || Number.isNaN(mins)) return '—';
    return `${Math.floor(mins / 60)} ó ${mins % 60} p`;
  }
  function humanClock(mins) {
    if (mins === null || Number.isNaN(mins)) return '—';
    return `${pad(Math.floor(mins / 60))}:${pad(mins % 60)}`;
  }

  function syncMode() {
    const mode = document.querySelector('input[name="time_mode"]:checked')?.value || 'end';
    if (endBlock) endBlock.classList.toggle('d-none', mode !== 'end');
    if (durationBlock) durationBlock.classList.toggle('d-none', mode !== 'duration');
    updateCalculated();
  }

  function updateCalculated() {
    const sh = parseIntSafe(startHour), sm = parseIntSafe(startMinute);
    const br = parseIntSafe(breakMinutes) || 0;

    if (sh === null || sm === null) {
      if (calcDuration) calcDuration.textContent = '—';
      if (calcEnd) calcEnd.textContent = '—';
      return;
    }

    const mode = document.querySelector('input[name="time_mode"]:checked')?.value || 'end';

    if (mode === 'end') {
      const eh = parseIntSafe(endHour), em = parseIntSafe(endMinute);
      if (eh === null || em === null) {
        if (calcDuration) calcDuration.textContent = '—';
        return;
      }

      let total = toMinutes(eh, em) - toMinutes(sh, sm);
      let overnight = false;
      if (total < 0) {
        total += 1440;
        overnight = true;
      }
      total -= br;
      if (calcDuration) calcDuration.textContent = total >= 0 ? `${human(total)}${overnight ? ' (+1 nap)' : ''}` : 'Érvénytelen';
    } else {
      const dh = parseIntSafe(durationHours) || 0;
      const dm = parseIntSafe(durationMinutes) || 0;
      const total = toMinutes(dh, dm) + br;
      const endTotal = toMinutes(sh, sm) + total;
      const overnight = endTotal >= 1440;
      if (calcEnd) calcEnd.textContent = `${humanClock(endTotal % 1440)}${overnight ? ' (+1 nap)' : ''}`;
    }
  }

  function parseFloatSafe(value) {
    const normalized = String(value ?? '').trim().replace(',', '.');
    if (normalized === '') return NaN;
    return parseFloat(normalized);
  }

  function getSelectedVehicleOption() {
    if (!vehicleSelect) return null;
    if (vehicleSelect.selectedIndex >= 0 && vehicleSelect.options[vehicleSelect.selectedIndex]) {
      return vehicleSelect.options[vehicleSelect.selectedIndex];
    }
    return Array.from(vehicleSelect.options || []).find(opt => String(opt.value) === String(vehicleSelect.value)) || null;
  }

  function updateTravel() {
    if (!calcTravel) return;
    const km = parseFloatSafe(travelKmInput?.value || '');
    const selected = getSelectedVehicleOption();
    const avg = parseFloatSafe(selected?.dataset?.avgSpeed || '0');

    if (!selected || !selected.value || !Number.isFinite(km) || km <= 0 || !Number.isFinite(avg) || avg <= 0) {
      calcTravel.textContent = '—';
      return;
    }

    const mins = Math.round((km / avg) * 60);
    const plate = String(selected.textContent || '').split('·')[0].trim();
    calcTravel.textContent = `${human(mins)}${plate ? ` (${plate})` : ''}`;
  }

  timeModeInputs.forEach(el => el.addEventListener('change', syncMode));
  [startHour, startMinute, endHour, endMinute, durationHours, durationMinutes, breakMinutes]
    .forEach(el => el && el.addEventListener('change', updateCalculated));
  [vehicleSelect, travelKmInput].forEach(el => el && el.addEventListener('change', updateTravel));
  [vehicleSelect, travelKmInput].forEach(el => el && el.addEventListener('input', updateTravel));
  [vehicleSelect, travelKmInput].forEach(el => el && el.addEventListener('keyup', updateTravel));
  [vehicleSelect, travelKmInput].forEach(el => el && el.addEventListener('blur', updateTravel));
  syncMode();
  updateTravel();

  // Drag & drop – áthelyezés másik napra
  const moveForm = document.createElement('form');
  moveForm.method = 'post';
  moveForm.action = '/move_entry.php';
  moveForm.className = 'd-none';
  moveForm.innerHTML = `
    <input type="hidden" name="id">
    <input type="hidden" name="new_date">
    <input type="hidden" name="month">
    <input type="hidden" name="employee_id">
  `;
  document.body.appendChild(moveForm);

  const params = new URLSearchParams(window.location.search);
  const month = params.get('month') || new Date().toISOString().slice(0, 7);
  const employeeId = params.get('employee_id') || '';
  let draggedId = null;

  document.querySelectorAll('.draggable-entry-row').forEach(row => {
    row.addEventListener('dragstart', () => {
      draggedId = row.dataset.entryId;
      row.classList.add('dragging');
    });
    row.addEventListener('dragend', () => {
      row.classList.remove('dragging');
    });
  });

  document.querySelectorAll('.calendar-dropzone').forEach(zone => {
    zone.addEventListener('dragover', (e) => {
      if (draggedId) {
        e.preventDefault();
        zone.classList.add('drop-target');
      }
    });

    zone.addEventListener('dragleave', () => zone.classList.remove('drop-target'));

    zone.addEventListener('drop', (e) => {
      if (!draggedId) return;
      e.preventDefault();
      zone.classList.remove('drop-target');

      moveForm.querySelector('input[name="id"]').value = draggedId;
      moveForm.querySelector('input[name="new_date"]').value = zone.dataset.date;
      moveForm.querySelector('input[name="month"]').value = month;
      moveForm.querySelector('input[name="employee_id"]').value = employeeId;
      moveForm.submit();
    });
  });

  // Másolás
  const copyForm = document.getElementById('copyEntryForm');
  const copyTargetDate = document.getElementById('copy_target_date');
  const copySourceInput = document.getElementById('copy_source_entry_id');
  const copyModalElement = document.getElementById('copyEntryModal');
  const copyModal = copyModalElement && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(copyModalElement) : null;
  let selectedCopyRow = null;

  document.querySelectorAll('.copy-entry-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      selectedCopyRow = btn.closest('.draggable-entry-row');
      if (copyTargetDate) copyTargetDate.value = '';
      if (copySourceInput && selectedCopyRow) {
        copySourceInput.value = selectedCopyRow.dataset.entryId || '';
      }
    });
  });

  if (copyForm) {
    copyForm.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!selectedCopyRow) return;

      const targetDate = copyTargetDate?.value || '';
      if (!targetDate) {
        alert('Adj meg egy céldátumot.');
        return;
      }
      if (selectedCopyRow.dataset.copyable !== '1') {
        alert('Ez a bejegyzés nem másolható.');
        return;
      }

      const [sh, sm] = (selectedCopyRow.dataset.startTime || '00:00').split(':');
      const [eh, em] = (selectedCopyRow.dataset.endTime || '00:00').split(':');

      const form = document.createElement('form');
      form.method = 'post';
      form.action = '/save_entry.php';
      form.className = 'd-none';

      const fields = {
        id: '0',
        month: month,
        employee_id: employeeId,
        entry_date: targetDate,
        entry_kind: selectedCopyRow.dataset.entryKind || 'work',
        start_hour: sh,
        start_minute: sm,
        end_hour: eh,
        end_minute: em,
        break_minutes: selectedCopyRow.dataset.breakMinutes || '0',
        note: selectedCopyRow.dataset.note || '',
        vehicle_id: selectedCopyRow.dataset.vehicleId || '',
        travel_km: selectedCopyRow.dataset.travelKm || '',
        time_mode: 'end'
      };

      Object.entries(fields).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      });

      document.body.appendChild(form);
      if (copyModal) copyModal.hide();
      form.submit();
    });
  }

  // Sablonok – munkaidő és távollét
  const workTemplate = document.getElementById('work_template_id');
  const absenceTemplate = document.getElementById('absence_template_id');
  const entryKind = document.getElementById('entry_kind');
  const absenceType = document.getElementById('absence_type_id');
  const entryNote = document.getElementById('entry_note');
  const absenceNote = document.getElementById('absence_note');

  function setSelectValue(selectEl, value) {
    if (!selectEl || value === undefined || value === null) return;

    let normalized = String(value).trim();
    const optionValues = Array.from(selectEl.options || []).map(opt => String(opt.value));

    if (normalized !== '' && !optionValues.includes(normalized)) {
      if (/^\d+$/.test(normalized)) {
        const numericNormalized = String(parseInt(normalized, 10));
        if (optionValues.includes(numericNormalized)) {
          normalized = numericNormalized;
        }
      }
    }

    selectEl.value = normalized;
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function splitTime(value) {
    const parts = String(value || '').split(':');
    return [parts[0] || '', parts[1] || ''];
  }

  if (workTemplate) {
    workTemplate.addEventListener('change', () => {
      const opt = workTemplate.options[workTemplate.selectedIndex];
      if (!opt || !opt.value) return;

      setSelectValue(entryKind, opt.dataset.entryKind || 'work');
      const [sh, sm] = splitTime(opt.dataset.startTime || '');
      const [eh, em] = splitTime(opt.dataset.endTime || '');
      setSelectValue(startHour, sh);
      setSelectValue(startMinute, sm);
      setSelectValue(endHour, eh);
      setSelectValue(endMinute, em);
      if (breakMinutes) {
        breakMinutes.value = opt.dataset.breakMinutes || '0';
        breakMinutes.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (entryNote) entryNote.value = opt.dataset.note || '';
      const modeEnd = document.getElementById('time_mode_end');
      if (modeEnd) {
        modeEnd.checked = true;
      }
      syncMode();
      updateCalculated();
    });
  }

  if (absenceTemplate) {
    absenceTemplate.addEventListener('change', () => {
      const opt = absenceTemplate.options[absenceTemplate.selectedIndex];
      if (!opt || !opt.value) return;
      setSelectValue(absenceType, opt.dataset.absenceTypeId || '');
      if (absenceNote) absenceNote.value = opt.dataset.note || '';
    });
  }

  // Admin templates page mode toggle
  const templateType = document.getElementById('template_type');
  const tplModeWork = document.querySelector('.template-mode-work');
  const tplModeAbs = document.querySelector('.template-mode-absence');
  function syncTemplateAdminMode() {
    if (!templateType) return;
    const isAbs = templateType.value === 'absence';
    if (tplModeWork) tplModeWork.classList.toggle('d-none', isAbs);
    if (tplModeAbs) tplModeAbs.classList.toggle('d-none', !isAbs);
  }
  if (templateType) {
    templateType.addEventListener('change', syncTemplateAdminMode);
    syncTemplateAdminMode();
  }
});
