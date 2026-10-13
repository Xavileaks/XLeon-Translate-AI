(() => {
  'use strict';
  const config = window.XLTAdmin;
  const status = document.getElementById('xlt-status');
  let job = null;
  let stopped = false;
  let running = false;
  const message = text => { status.textContent = text; };
  async function request(action, values = {}) {
    const body = new URLSearchParams({action: `xlt_${action}`, nonce: config.nonce, ...values});
    const response = await fetch(config.ajax, {method: 'POST', credentials: 'same-origin', body});
    const result = await response.json();
    if (!result.success) throw new Error(result.data?.message || 'No se pudo completar la petición. Recarga para renovar la sesión.');
    return result.data;
  }
  const fields = () => ({url: document.getElementById('xlt-url').value, target: document.getElementById('xlt-target').value});
  for (const button of document.querySelectorAll('.xlt-models')) {
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const models = await request('models', {provider: button.dataset.provider});
        const list = document.getElementById(`xlt-${button.dataset.provider}-models`);
        list.replaceChildren(...models.map(id => { const option = document.createElement('option'); option.value = id; return option; }));
        message(`${models.length} modelos disponibles. Selecciona uno de texto y guarda los ajustes.`);
      } catch (error) { message(error.message); }
      finally { button.disabled = false; }
    });
  }
  document.getElementById('xlt-read').addEventListener('click', async event => {
    event.currentTarget.disabled = true;
    document.getElementById('xlt-ai').disabled = true;
    job = null;
    message('Leyendo las cadenas de la página…');
    try {
      job = await request('prepare', fields());
      message(`${job.total} cadenas detectadas, ${job.added} nuevas, ${job.pending} pendientes. Pulsa «Filtrar / actualizar» para editarlas o traduce los pendientes con IA.`);
      document.getElementById('xlt-ai').disabled = !job.pending;
    } catch (error) { message(error.message); }
    finally { document.getElementById('xlt-read').disabled = false; }
  });
  document.getElementById('xlt-scan').addEventListener('click', async () => {
    // Open during the click so the browser does not block an asynchronous popup.
    const tab = window.open('about:blank', '_blank');
    if (tab) tab.opener = null;
    try {
      const result = await request('scan_link', fields());
      if (tab) tab.location.href = result.url;
      const link = document.createElement('a');
      link.href = result.url; link.textContent = 'Abrir captura'; link.target = '_blank'; link.rel = 'noopener';
      status.replaceChildren(document.createTextNode('Interactúa con la página y después actualiza el diccionario. No se realizan llamadas de IA. '), link);
    } catch (error) { if (tab) tab.close(); message(error.message); }
  });
  document.getElementById('xlt-stop').addEventListener('click', () => { stopped = true; message('Se detendrá cuando termine el lote actual.'); });
  document.getElementById('xlt-ai').addEventListener('click', async () => {
    if (!job || running) return;
    running = true;
    stopped = false;
    document.getElementById('xlt-ai').disabled = true;
    document.getElementById('xlt-read').disabled = true;
    document.getElementById('xlt-stop').hidden = false;
    let complete = false;
    try {
      while (!stopped) {
        message('Traduciendo con la API… Las cadenas guardadas se conservan si detienes el proceso.');
        const result = await request('batch', {job: job.job});
        message(`${result.done} de ${result.total} cadenas pendientes traducidas.`);
        if (result.complete) { complete = true; message('Traducciones guardadas. Actualiza el diccionario para revisarlas.'); break; }
      }
    } catch (error) { message(error.message); }
    finally {
      running = false;
      document.getElementById('xlt-ai').disabled = complete;
      document.getElementById('xlt-read').disabled = false;
      document.getElementById('xlt-stop').hidden = true;
    }
  });
  for (const button of document.querySelectorAll('.xlt-row')) {
    button.addEventListener('click', async () => {
      button.disabled = true;
      const field = document.getElementById(`xlt-string-${button.dataset.id}`);
      const previous = field.value;
      try {
        const result = await request('row', {id: button.dataset.id});
        if (field.value !== previous) { message('El texto cambió mientras la IA respondía. Se conserva tu edición.'); return; }
        field.value = result.text;
        field.dispatchEvent(new Event('input', {bubbles: true}));
        message('Sugerencia preparada. Revísala y pulsa «Guardar para todas las apariciones».');
      } catch (error) { message(error.message); }
      finally { button.disabled = false; }
    });
  }
})();
