(function () {
  const ui = window.DEVICE_CONFIG_UI || {};
  const builtinEvents = ui.builtinEvents || {};

  function addGenericRow(container, templateId) {
    const tpl = document.getElementById(templateId);
    if (!tpl || !container) return;
    const index = container.querySelectorAll('[data-row]').length;
    const html = tpl.innerHTML.replace(/__INDEX__/g, String(index));
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();
    const row = wrapper.firstElementChild;
    if (row) container.appendChild(row);
  }

  function currentContactMeta() {
    const out = {};
    document.querySelectorAll('[data-contact-key]').forEach(card => {
      const key = card.getAttribute('data-contact-key');
      out[key] = {
        key,
        mode: card.querySelector('[data-contact-field="mode"]')?.value || 'nc',
        name: card.querySelector('[data-contact-field="name"]')?.value?.trim() || key,
        open_label: card.querySelector('[data-contact-field="open_label"]')?.value?.trim() || 'Nyitva',
        closed_label: card.querySelector('[data-contact-field="closed_label"]')?.value?.trim() || 'Zárva'
      };
    });
    return out;
  }

  function currentGroupNames() {
    return Array.from(document.querySelectorAll('#groups-rows input[name$="[name]"]'))
      .map(el => el.value.trim())
      .filter(Boolean);
  }

  function currentRuleIds() {
    return Array.from(document.querySelectorAll('.rule-id-input'))
      .map(el => el.value.trim())
      .filter(Boolean);
  }

  function parseActionString(raw) {
    raw = String(raw || '').trim();
    if (!raw) return { type: 'mattermost', target: '' };
    if (raw === 'mattermost') return { type: 'mattermost', target: '' };
    if (raw.startsWith('sms:group_')) return { type: 'sms_group', target: raw.slice(4) };
    if (raw.startsWith('sms:')) return { type: 'sms_phone', target: raw.slice(4) };
    if (raw.startsWith('call:group_')) return { type: 'call_group', target: raw.slice(5) };
    if (raw.startsWith('call:')) return { type: 'call_phone', target: raw.slice(5) };
    return { type: 'custom', target: raw };
  }

  function buildActionString(row) {
    const type = row.querySelector('[data-action-type]')?.value || 'mattermost';
    const group = row.querySelector('[data-action-group]')?.value?.trim() || '';
    const phone = row.querySelector('[data-action-phone]')?.value?.trim() || '';
    const custom = row.querySelector('[data-action-custom]')?.value?.trim() || '';
    if (type === 'mattermost') return 'mattermost';
    if (type === 'sms_group') return group ? `sms:${group}` : '';
    if (type === 'sms_phone') return phone ? `sms:${phone}` : '';
    if (type === 'call_group') return group ? `call:${group}` : '';
    if (type === 'call_phone') return phone ? `call:${phone}` : '';
    return custom;
  }

  function actionRowHtml(action) {
    const parsed = parseActionString(action);
    const groups = currentGroupNames();
    const groupOptions = ['<option value="">Válassz csoportot…</option>']
      .concat(groups.map(name => `<option value="${escapeHtml(name)}" ${name === parsed.target ? 'selected' : ''}>${escapeHtml(name)}</option>`))
      .join('');
    return `
      <div class="action-builder-row" data-action-row>
        <label>
          <span>Akció</span>
          <select data-action-type>
            <option value="mattermost" ${parsed.type === 'mattermost' ? 'selected' : ''}>Mattermost üzenet</option>
            <option value="sms_group" ${parsed.type === 'sms_group' ? 'selected' : ''}>SMS csoportnak</option>
            <option value="sms_phone" ${parsed.type === 'sms_phone' ? 'selected' : ''}>SMS telefonszámra</option>
            <option value="call_group" ${parsed.type === 'call_group' ? 'selected' : ''}>Hívás csoportnak</option>
            <option value="call_phone" ${parsed.type === 'call_phone' ? 'selected' : ''}>Hívás telefonszámra</option>
            <option value="custom" ${parsed.type === 'custom' ? 'selected' : ''}>Egyedi technikai akció</option>
          </select>
        </label>
        <label class="action-target-group ${['sms_group','call_group'].includes(parsed.type) ? '' : 'is-hidden'}" ${['sms_group','call_group'].includes(parsed.type) ? '' : 'hidden aria-hidden="true"'}>
          <span>Csoport</span>
          <select data-action-group>${groupOptions}</select>
        </label>
        <label class="action-target-phone ${['sms_phone','call_phone'].includes(parsed.type) ? '' : 'is-hidden'}" ${['sms_phone','call_phone'].includes(parsed.type) ? '' : 'hidden aria-hidden="true"'}>
          <span>Telefonszám</span>
          <input type="text" data-action-phone value="${escapeHtml(['sms_phone','call_phone'].includes(parsed.type) ? parsed.target : '')}" placeholder="+36301234567">
        </label>
        <label class="action-target-custom ${parsed.type === 'custom' ? '' : 'is-hidden'}" ${parsed.type === 'custom' ? '' : 'hidden aria-hidden="true"'}>
          <span>Egyedi érték</span>
          <input type="text" data-action-custom value="${escapeHtml(parsed.type === 'custom' ? parsed.target : '')}" placeholder="példa: sms:group_1">
        </label>
        <div class="action-row-buttons"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-action>Akció törlése</button></div>
      </div>`;
  }

  function buildRouteOptions(selectedValue) {
    const rules = currentRuleIds();
    let html = '<optgroup label="Rendszer események">';
    Object.entries(builtinEvents).forEach(([key, label]) => {
      const value = `builtin:${key}`;
      html += `<option value="${escapeHtml(value)}" ${value === selectedValue ? 'selected' : ''}>${escapeHtml(label)}</option>`;
    });
    html += '</optgroup><optgroup label="Egyedi szabályok">';
    rules.forEach(ruleId => {
      const value = `rule:${ruleId}`;
      html += `<option value="${escapeHtml(value)}" ${value === selectedValue ? 'selected' : ''}>${escapeHtml('Egyedi szabály: ' + ruleId)}</option>`;
    });
    html += '</optgroup>';
    html += `<option value="custom" ${selectedValue === 'custom' ? 'selected' : ''}>Egyedi technikai kulcs</option>`;
    return html;
  }

  function routeRowHtml(index, data) {
    const actions = (data.actions && data.actions.length ? data.actions : ['mattermost']).map(action => actionRowHtml(action)).join('');
    return `
      <div class="dynamic-row route-builder" data-row data-route-row>
        <input type="hidden" name="routes[${index}][event]" data-route-event-hidden value="${escapeHtml(data.event || '')}">
        <input type="hidden" name="routes[${index}][actions]" data-route-actions-hidden value="${escapeHtml((data.actions || []).join(','))}">
        <div class="dynamic-row-grid route-builder-grid">
          <label>
            <span>Esemény</span>
            <select data-route-event-select>${buildRouteOptions(data.selectValue || 'builtin:device_boot')}</select>
          </label>
          <label class="route-custom-event-wrap ${data.selectValue === 'custom' ? '' : 'is-hidden'}">
            <span>Egyedi kulcs</span>
            <input type="text" data-route-custom-event value="${escapeHtml(data.customEvent || '')}" placeholder="példa: temp_warn">
          </label>
          <div class="full-span action-builder-box">
            <div class="action-builder-head">
              <span>Akciók</span>
              <button type="button" class="btn btn-outline-primary btn-sm" data-add-action>Akció hozzáadása</button>
            </div>
            <div class="action-builder-list" data-action-list>${actions}</div>
          </div>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Route törlése</button></div>
      </div>`;
  }

  function ruleRowHtml(index, data) {
    const contacts = currentContactMeta();
    const actions = (data.actions && data.actions.length ? data.actions : ['mattermost']).map(action => actionRowHtml(action)).join('');
    const contactOptions = Object.values(contacts).map(meta => {
      const disabled = meta.mode === 'unused' ? 'disabled' : '';
      const selected = meta.key === (data.contactKey || 'c1') ? 'selected' : '';
      return `<option value="${escapeHtml(meta.key)}" ${selected} ${disabled}>${escapeHtml(meta.name)}</option>`;
    }).join('');
    const currentContact = contacts[data.contactKey || 'c1'] || { open_label: 'Nyitva', closed_label: 'Zárva' };
    return `
      <div class="dynamic-row rule-builder" data-row data-rule-row>
        <input type="hidden" name="rules[${index}][rule_id]" data-rule-hidden="rule_id" value="${escapeHtml(data.ruleId || '')}">
        <input type="hidden" name="rules[${index}][type]" data-rule-hidden="type" value="${escapeHtml(data.type || 'threshold')}">
        <input type="hidden" name="rules[${index}][sensor]" data-rule-hidden="sensor" value="${escapeHtml(data.sensor || 'temperature')}">
        <input type="hidden" name="rules[${index}][operator]" data-rule-hidden="operator" value="${escapeHtml(data.operator || '>=')}">
        <input type="hidden" name="rules[${index}][value]" data-rule-hidden="value" value="${escapeHtml(data.value || '')}">
        <input type="hidden" name="rules[${index}][for_sec]" data-rule-hidden="for_sec" value="${escapeHtml(data.forSec || '')}">
        <input type="hidden" name="rules[${index}][delta]" data-rule-hidden="delta" value="${escapeHtml(data.delta || '')}">
        <input type="hidden" name="rules[${index}][window_sec]" data-rule-hidden="window_sec" value="${escapeHtml(data.windowSec || '')}">
        <input type="hidden" name="rules[${index}][actions]" data-rule-hidden="actions" value="${escapeHtml((data.actions || []).join(','))}">
        <div class="dynamic-row-grid rule-builder-grid">
          <label><span>Szabály azonosító</span><input type="text" class="rule-id-input" data-rule-ui="rule_id" value="${escapeHtml(data.ruleId || '')}" placeholder="példa: temp_warn"></label>
          <label><span>Szabály típusa</span>
            <select data-rule-ui="mode">
              <option value="threshold" ${data.mode === 'threshold' ? 'selected' : ''}>Küszöbérték</option>
              <option value="trend_up" ${data.mode === 'trend_up' ? 'selected' : ''}>Emelkedő trend</option>
              <option value="trend_down" ${data.mode === 'trend_down' ? 'selected' : ''}>Csökkenő trend</option>
              <option value="contact_state" ${data.mode === 'contact_state' ? 'selected' : ''}>Kontakt állapot</option>
              <option value="custom" ${data.mode === 'custom' ? 'selected' : ''}>Haladó / egyedi</option>
            </select>
          </label>
          <div class="rule-mode-block ${data.mode === 'threshold' ? '' : 'is-hidden'}" data-rule-block="threshold">
            <label><span>Mért érték</span><select data-rule-ui="threshold_sensor">
              <option value="temperature" ${data.sensor === 'temperature' ? 'selected' : ''}>Hőmérséklet</option>
              <option value="humidity" ${data.sensor === 'humidity' ? 'selected' : ''}>Páratartalom</option>
              <option value="air_quality" ${data.sensor === 'air_quality' ? 'selected' : ''}>Levegő minőség</option>
              <option value="battery_pct" ${data.sensor === 'battery_pct' ? 'selected' : ''}>Akkumulátor %</option>
            </select></label>
            <label><span>Feltétel</span><select data-rule-ui="threshold_operator">
              <option value=">=" ${data.operator === '>=' ? 'selected' : ''}>nagyobb vagy egyenlő</option>
              <option value=">" ${data.operator === '>' ? 'selected' : ''}>nagyobb mint</option>
              <option value="<=" ${data.operator === '<=' ? 'selected' : ''}>kisebb vagy egyenlő</option>
              <option value="<" ${data.operator === '<' ? 'selected' : ''}>kisebb mint</option>
              <option value="==" ${data.operator === '==' ? 'selected' : ''}>egyenlő</option>
              <option value="!=" ${data.operator === '!=' ? 'selected' : ''}>nem egyenlő</option>
            </select></label>
            <label><span>Érték</span><input type="text" data-rule-ui="threshold_value" value="${escapeHtml(data.value || '')}"></label>
            <label><span>Legyen fenn legalább (sec)</span><input type="number" min="0" step="1" data-rule-ui="threshold_for_sec" value="${escapeHtml(data.forSec || '')}"></label>
          </div>
          <div class="rule-mode-block ${['trend_up','trend_down'].includes(data.mode) ? '' : 'is-hidden'}" data-rule-block="trend">
            <label><span>Mért érték</span><select data-rule-ui="trend_sensor">
              <option value="temperature" ${data.sensor === 'temperature' ? 'selected' : ''}>Hőmérséklet</option>
              <option value="humidity" ${data.sensor === 'humidity' ? 'selected' : ''}>Páratartalom</option>
              <option value="air_quality" ${data.sensor === 'air_quality' ? 'selected' : ''}>Levegő minőség</option>
              <option value="battery_pct" ${data.sensor === 'battery_pct' ? 'selected' : ''}>Akkumulátor %</option>
            </select></label>
            <label><span>Változás mértéke</span><input type="text" data-rule-ui="trend_delta" value="${escapeHtml(data.delta || '')}"></label>
            <label><span>Ablak (sec)</span><input type="number" min="0" step="1" data-rule-ui="trend_window_sec" value="${escapeHtml(data.windowSec || '')}"></label>
          </div>
          <div class="rule-mode-block ${data.mode === 'contact_state' ? '' : 'is-hidden'}" data-rule-block="contact_state">
            <label><span>Kontakt</span><select data-rule-ui="contact_key">${contactOptions}</select></label>
            <label><span>Kért állapot</span><select data-rule-ui="contact_state">
              <option value="open" ${data.contactState === 'open' ? 'selected' : ''}>${escapeHtml(currentContact.open_label || 'Nyitva')}</option>
              <option value="closed" ${data.contactState === 'closed' ? 'selected' : ''}>${escapeHtml(currentContact.closed_label || 'Zárva')}</option>
            </select></label>
            <label><span>Legyen fenn legalább (sec)</span><input type="number" min="0" step="1" data-rule-ui="contact_for_sec" value="${escapeHtml(data.forSec || '')}"></label>
          </div>
          <div class="rule-mode-block full-span ${data.mode === 'custom' ? '' : 'is-hidden'}" data-rule-block="custom">
            <div class="rule-custom-grid">
              <label><span>Típus kulcs</span><input type="text" data-rule-ui="custom_type" value="${escapeHtml(data.type || '')}"></label>
              <label><span>Szenzor kulcs</span><input type="text" data-rule-ui="custom_sensor" value="${escapeHtml(data.sensor || '')}"></label>
              <label><span>Operátor</span><input type="text" data-rule-ui="custom_operator" value="${escapeHtml(data.operator || '')}"></label>
              <label><span>Érték</span><input type="text" data-rule-ui="custom_value" value="${escapeHtml(data.value || '')}"></label>
              <label><span>for_sec</span><input type="number" data-rule-ui="custom_for_sec" value="${escapeHtml(data.forSec || '')}"></label>
              <label><span>delta</span><input type="text" data-rule-ui="custom_delta" value="${escapeHtml(data.delta || '')}"></label>
              <label><span>window_sec</span><input type="number" data-rule-ui="custom_window_sec" value="${escapeHtml(data.windowSec || '')}"></label>
            </div>
          </div>
          <div class="full-span action-builder-box">
            <div class="action-builder-head"><span>Akciók</span><button type="button" class="btn btn-outline-primary btn-sm" data-add-action>Akció hozzáadása</button></div>
            <div class="action-builder-list" data-action-list>${actions}</div>
          </div>
        </div>
        <div class="dynamic-row-actions"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-row>Szabály törlése</button></div>
      </div>`;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setFieldVisibility(wrapper, visible) {
    if (!wrapper) return;
    wrapper.classList.toggle('is-hidden', !visible);
    wrapper.hidden = !visible;
    wrapper.setAttribute('aria-hidden', visible ? 'false' : 'true');
    wrapper.querySelectorAll('input, select, textarea').forEach(el => {
      el.disabled = !visible;
      if (!visible) {
        el.blur?.();
      }
    });
  }

  function syncActionRow(row) {
    const type = row.querySelector('[data-action-type]')?.value || 'mattermost';
    setFieldVisibility(row.querySelector('.action-target-group'), ['sms_group', 'call_group'].includes(type));
    setFieldVisibility(row.querySelector('.action-target-phone'), ['sms_phone', 'call_phone'].includes(type));
    setFieldVisibility(row.querySelector('.action-target-custom'), type === 'custom');
  }

  function refreshActionGroupOptions() {
    const groups = currentGroupNames();
    document.querySelectorAll('[data-action-group]').forEach(select => {
      const current = select.value;
      select.innerHTML = '<option value="">Válassz csoportot…</option>' + groups.map(name => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
      if (groups.includes(current)) select.value = current;
    });
  }

  function syncRouteRow(row) {
    const select = row.querySelector('[data-route-event-select]');
    const customInput = row.querySelector('[data-route-custom-event]');
    const hiddenEvent = row.querySelector('[data-route-event-hidden]');
    const hiddenActions = row.querySelector('[data-route-actions-hidden]');
    if (!select || !hiddenEvent || !hiddenActions) return;
    const value = select.value;
    row.querySelector('.route-custom-event-wrap')?.classList.toggle('is-hidden', value !== 'custom');
    if (value.startsWith('builtin:')) hiddenEvent.value = value.slice(8);
    else if (value.startsWith('rule:')) hiddenEvent.value = value.slice(5);
    else hiddenEvent.value = customInput?.value?.trim() || '';
    hiddenActions.value = Array.from(row.querySelectorAll('[data-action-row]')).map(buildActionString).filter(Boolean).join(',');
  }

  function refreshRouteSelectors() {
    document.querySelectorAll('[data-route-event-select]').forEach(select => {
      const current = select.value;
      select.innerHTML = buildRouteOptions(current);
      if (![...select.options].some(opt => opt.value === current)) {
        select.value = 'custom';
      }
      const row = select.closest('[data-route-row]');
      if (row) syncRouteRow(row);
    });
  }

  function updateContactStateLabels(row) {
    const contacts = currentContactMeta();
    const key = row.querySelector('[data-rule-ui="contact_key"]')?.value || 'c1';
    const meta = contacts[key] || { open_label: 'Nyitva', closed_label: 'Zárva' };
    const stateSelect = row.querySelector('[data-rule-ui="contact_state"]');
    if (stateSelect) {
      const current = stateSelect.value;
      stateSelect.innerHTML = `<option value="open">${escapeHtml(meta.open_label || 'Nyitva')}</option><option value="closed">${escapeHtml(meta.closed_label || 'Zárva')}</option>`;
      stateSelect.value = current || 'open';
    }
    const contactSelect = row.querySelector('[data-rule-ui="contact_key"]');
    if (contactSelect) {
      const selected = contactSelect.value;
      contactSelect.innerHTML = Object.values(contacts).map(metaItem => {
        const disabled = metaItem.mode === 'unused' ? 'disabled' : '';
        const sel = metaItem.key === selected ? 'selected' : '';
        return `<option value="${escapeHtml(metaItem.key)}" ${sel} ${disabled}>${escapeHtml(metaItem.name)}</option>`;
      }).join('');
      if (![...contactSelect.options].some(opt => opt.value === selected && !opt.disabled)) {
        const firstEnabled = [...contactSelect.options].find(opt => !opt.disabled);
        if (firstEnabled) contactSelect.value = firstEnabled.value;
      }
    }
  }

  function syncRuleRow(row) {
    const hidden = name => row.querySelector(`[data-rule-hidden="${name}"]`);
    const mode = row.querySelector('[data-rule-ui="mode"]')?.value || 'threshold';
    row.querySelectorAll('[data-rule-block]').forEach(block => {
      block.classList.toggle('is-hidden', block.getAttribute('data-rule-block') !== mode && !(mode.startsWith('trend') && block.getAttribute('data-rule-block') === 'trend'));
    });
    hidden('rule_id').value = row.querySelector('[data-rule-ui="rule_id"]')?.value?.trim() || '';
    hidden('actions').value = Array.from(row.querySelectorAll('[data-action-row]')).map(buildActionString).filter(Boolean).join(',');

    if (mode === 'threshold') {
      hidden('type').value = 'threshold';
      hidden('sensor').value = row.querySelector('[data-rule-ui="threshold_sensor"]')?.value || 'temperature';
      hidden('operator').value = row.querySelector('[data-rule-ui="threshold_operator"]')?.value || '>=';
      hidden('value').value = row.querySelector('[data-rule-ui="threshold_value"]')?.value?.trim() || '';
      hidden('for_sec').value = row.querySelector('[data-rule-ui="threshold_for_sec"]')?.value?.trim() || '';
      hidden('delta').value = '';
      hidden('window_sec').value = '';
    } else if (mode === 'trend_up' || mode === 'trend_down') {
      hidden('type').value = mode;
      hidden('sensor').value = row.querySelector('[data-rule-ui="trend_sensor"]')?.value || 'temperature';
      hidden('operator').value = '';
      hidden('value').value = '';
      hidden('for_sec').value = '';
      hidden('delta').value = row.querySelector('[data-rule-ui="trend_delta"]')?.value?.trim() || '';
      hidden('window_sec').value = row.querySelector('[data-rule-ui="trend_window_sec"]')?.value?.trim() || '';
    } else if (mode === 'contact_state') {
      const key = row.querySelector('[data-rule-ui="contact_key"]')?.value || 'c1';
      hidden('type').value = 'contact_state';
      hidden('sensor').value = key.replace('c', 'contact_');
      hidden('operator').value = '==';
      hidden('value').value = row.querySelector('[data-rule-ui="contact_state"]')?.value || 'open';
      hidden('for_sec').value = row.querySelector('[data-rule-ui="contact_for_sec"]')?.value?.trim() || '';
      hidden('delta').value = '';
      hidden('window_sec').value = '';
    } else {
      hidden('type').value = row.querySelector('[data-rule-ui="custom_type"]')?.value?.trim() || '';
      hidden('sensor').value = row.querySelector('[data-rule-ui="custom_sensor"]')?.value?.trim() || '';
      hidden('operator').value = row.querySelector('[data-rule-ui="custom_operator"]')?.value?.trim() || '';
      hidden('value').value = row.querySelector('[data-rule-ui="custom_value"]')?.value?.trim() || '';
      hidden('for_sec').value = row.querySelector('[data-rule-ui="custom_for_sec"]')?.value?.trim() || '';
      hidden('delta').value = row.querySelector('[data-rule-ui="custom_delta"]')?.value?.trim() || '';
      hidden('window_sec').value = row.querySelector('[data-rule-ui="custom_window_sec"]')?.value?.trim() || '';
    }
  }

  function addActionRow(list, defaultAction) {
    if (!list) return;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = actionRowHtml(defaultAction || 'mattermost').trim();
    const row = wrapper.firstElementChild;
    list.appendChild(row);
    syncActionRow(row);
  }

  function addRuleRow() {
    const container = document.getElementById('rules-rows');
    if (!container) return;
    const index = container.querySelectorAll('[data-rule-row]').length;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = ruleRowHtml(index, { mode: 'threshold', type: 'threshold', sensor: 'temperature', operator: '>=', value: '', forSec: '60', delta: '', windowSec: '', actions: ['mattermost'], contactKey: 'c1', contactState: 'open' }).trim();
    const row = wrapper.firstElementChild;
    container.appendChild(row);
    updateContactStateLabels(row);
    syncRuleRow(row);
    refreshRouteSelectors();
  }

  function addRouteRow() {
    const container = document.getElementById('routes-rows');
    if (!container) return;
    const index = container.querySelectorAll('[data-route-row]').length;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = routeRowHtml(index, { event: 'device_boot', selectValue: 'builtin:device_boot', customEvent: '', actions: ['mattermost'] }).trim();
    const row = wrapper.firstElementChild;
    container.appendChild(row);
    syncRouteRow(row);
  }

  function initialize() {
    document.querySelectorAll('[data-action-row]').forEach(syncActionRow);
    document.querySelectorAll('[data-rule-row]').forEach(row => { updateContactStateLabels(row); syncRuleRow(row); });
    document.querySelectorAll('[data-route-row]').forEach(syncRouteRow);
    refreshRouteSelectors();
    refreshActionGroupOptions();
  }

  document.addEventListener('click', function (e) {
    const addBtn = e.target.closest('[data-add-row]');
    if (addBtn) {
      e.preventDefault();
      const container = document.querySelector(addBtn.getAttribute('data-target'));
      addGenericRow(container, addBtn.getAttribute('data-add-row'));
      refreshActionGroupOptions();
      return;
    }

    if (e.target.closest('#add-rule-row')) {
      e.preventDefault();
      addRuleRow();
      return;
    }
    if (e.target.closest('#add-route-row')) {
      e.preventDefault();
      addRouteRow();
      return;
    }
    const addAction = e.target.closest('[data-add-action]');
    if (addAction) {
      e.preventDefault();
      addActionRow(addAction.closest('.action-builder-box')?.querySelector('[data-action-list]'), 'mattermost');
      return;
    }
    const removeAction = e.target.closest('[data-remove-action]');
    if (removeAction) {
      e.preventDefault();
      const row = removeAction.closest('[data-action-row]');
      const parent = row?.parentElement;
      if (row) row.remove();
      if (parent && !parent.querySelector('[data-action-row]')) addActionRow(parent, 'mattermost');
      document.querySelectorAll('[data-route-row]').forEach(syncRouteRow);
      document.querySelectorAll('[data-rule-row]').forEach(syncRuleRow);
      return;
    }
    const removeBtn = e.target.closest('[data-remove-row]');
    if (removeBtn) {
      e.preventDefault();
      const row = removeBtn.closest('[data-row]');
      if (row) row.remove();
      refreshRouteSelectors();
      refreshActionGroupOptions();
    }
  });

  document.addEventListener('change', function (e) {
    const target = e.target;
    if (target.matches('[data-action-type], [data-action-group], [data-action-phone], [data-action-custom]')) {
      const actionRow = target.closest('[data-action-row]');
      if (actionRow) syncActionRow(actionRow);
      const routeRow = target.closest('[data-route-row]');
      if (routeRow) syncRouteRow(routeRow);
      const ruleRow = target.closest('[data-rule-row]');
      if (ruleRow) syncRuleRow(ruleRow);
      return;
    }
    if (target.matches('[data-route-event-select], [data-route-custom-event]')) {
      const row = target.closest('[data-route-row]');
      if (row) syncRouteRow(row);
      return;
    }
    if (target.matches('[data-rule-ui], .rule-id-input')) {
      const row = target.closest('[data-rule-row]');
      if (row) {
        if (target.matches('[data-rule-ui="contact_key"]')) updateContactStateLabels(row);
        syncRuleRow(row);
      }
      refreshRouteSelectors();
      return;
    }
    if (target.matches('[data-contact-field]')) {
      document.querySelectorAll('[data-rule-row]').forEach(row => { updateContactStateLabels(row); syncRuleRow(row); });
      return;
    }
    if (target.matches('#groups-rows input[name$="[name]"]')) {
      refreshActionGroupOptions();
    }
  });

  document.addEventListener('input', function (e) {
    if (e.target.matches('.rule-id-input')) {
      const row = e.target.closest('[data-rule-row]');
      if (row) syncRuleRow(row);
      refreshRouteSelectors();
    }
    if (e.target.matches('#groups-rows input[name$="[name]"]')) {
      refreshActionGroupOptions();
    }
    if (e.target.matches('[data-route-custom-event]')) {
      const row = e.target.closest('[data-route-row]');
      if (row) syncRouteRow(row);
    }
  });

  document.addEventListener('submit', function (e) {
    const form = e.target.closest('#device-config-form');
    if (!form) return;
    document.querySelectorAll('[data-route-row]').forEach(syncRouteRow);
    document.querySelectorAll('[data-rule-row]').forEach(syncRuleRow);
  });

  initialize();
})();
