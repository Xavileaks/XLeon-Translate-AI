(() => {
  'use strict';
  const config = window.XLTFrontend;
  if (!config) return;
  const dictionary = config.dictionary || {};
  const changes = new WeakMap();
  const pending = new Set();
  const seen = new Set();
  const excluded = 'script,style,noscript,code,pre,textarea,svg,math,template,[translate="no"],[data-xlt-ignore],.notranslate,.xlt-switcher,#wpadminbar';
  const attributes = ['alt', 'title', 'placeholder', 'aria-label'];
  let timer;
  let sending = false;
  let paused = false;
  let captured = 0;
  let scanStatus;
  const eligible = text => text.length <= 3500 && /\p{L}/u.test(text) && !/^(https?:\/\/|www\.|[^\s@]+@[^\s@]+\.[^\s@]+$)/i.test(text);
  function visit(node, attribute) {
    if (attribute === 'value' && !node.matches('input[type="submit"],input[type="button"],input[type="reset"]')) return;
    const owner = attribute ? node : node.parentElement;
    if (!owner || owner.closest(excluded)) return;
    const raw = attribute ? node.getAttribute(attribute) : node.nodeValue;
    if (raw == null) return;
    const text = raw.trim();
    if (config.scan && eligible(text) && !seen.has(text)) pending.add(text);
    if (!Object.prototype.hasOwnProperty.call(dictionary, text)) return;
    const key = attribute || '#text';
    const record = changes.get(node) || {};
    if (record[key] === raw) return;
    const leading = raw.match(/^\s*/)[0];
    const trailing = raw.match(/\s*$/)[0];
    const translated = leading + dictionary[text] + trailing;
    record[key] = translated;
    changes.set(node, record);
    if (attribute) node.setAttribute(attribute, translated);
    else node.nodeValue = translated;
  }
  function walk(root) {
    if (root.nodeType === Node.TEXT_NODE) { visit(root); return; }
    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) return;
    if (root.nodeType === Node.ELEMENT_NODE && root.closest(excluded)) return;
    const elements = root.nodeType === Node.ELEMENT_NODE ? [root, ...root.querySelectorAll('*')] : [...root.querySelectorAll('*')];
    for (const element of elements) {
      for (const attribute of attributes) if (element.hasAttribute(attribute)) visit(element, attribute);
      if (element.matches('input[type="submit"],input[type="button"],input[type="reset"]')) visit(element, 'value');
    }
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) visit(walker.currentNode);
  }
  async function flush() {
    if (!config.scan || paused || sending || !pending.size) return;
    sending = true;
    const strings = [];
    let length = 2;
    for (const text of pending) {
      const size = JSON.stringify(text).length * 3;
      if (strings.length >= 150 || length + size > 90000) break;
      strings.push(text); length += size;
      pending.delete(text); seen.add(text);
    }
    try {
      const body = new URLSearchParams({action: 'xlt_capture', nonce: config.scan.nonce, url: config.scan.url, target: config.scan.target, strings: JSON.stringify(strings)});
      const response = await fetch(config.scan.ajax, {method: 'POST', credentials: 'same-origin', body});
      const result = await response.json();
      if (!result.success) throw new Error(result.data?.message || 'No se pudo guardar. Recarga la captura.');
      captured += result.data.added;
      scanStatus.textContent = `Captura activa · ${captured} cadenas nuevas guardadas. Abre menús y desplegables para detectar más textos.`;
    } catch (error) {
      for (const text of strings) { seen.delete(text); pending.add(text); }
      paused = true;
      scanStatus.textContent = `Captura pausada: ${error.message}`;
    } finally {
      sending = false;
      if (pending.size && !paused) timer = setTimeout(flush, 500);
    }
  }
  if (config.scan) {
    const bar = document.createElement('aside');
    bar.setAttribute('data-xlt-ignore', '');
    bar.style.cssText = 'position:fixed;bottom:12px;left:12px;right:12px;z-index:2147483647;padding:12px 16px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;color:#1d2327;font:14px/1.5 system-ui;box-shadow:0 2px 8px #0002';
    scanStatus = document.createElement('span');
    scanStatus.setAttribute('role', 'status');
    scanStatus.textContent = 'Capturando cadenas…';
    const link = document.createElement('a');
    link.href = config.scan.backend;
    link.textContent = 'Abrir diccionario';
    link.style.cssText = 'margin-left:16px;color:#2271b1;text-decoration:underline';
    bar.append(scanStatus, link);
    document.body.append(bar);
    for (const text of config.scan.gettext || []) if (eligible(text.trim())) pending.add(text.trim());
    walk(document);
    timer = setTimeout(flush, 300);
  }
  // Initial HTML was translated on the server; translate only subsequent DOM updates.
  new MutationObserver(records => {
    for (const record of records) {
      if (record.type === 'childList') for (const node of record.addedNodes) walk(node);
      else if (record.type === 'characterData') visit(record.target);
      else if (record.type === 'attributes') visit(record.target, record.attributeName);
    }
    if (config.scan && pending.size && !sending && !paused) {
      clearTimeout(timer);
      timer = setTimeout(flush, 400);
    }
  }).observe(document.documentElement, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: [...attributes, 'value']});
})();
