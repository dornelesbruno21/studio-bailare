'use strict';
// Accept only our admission URL (never navigate to arbitrary QR contents).
function admissionToken(raw, origin) {
  if (/^[a-f0-9]{64}$/.test(raw)) return raw;
  try {
    const u = new URL(raw);
    if (u.origin !== origin || u.pathname !== '/admin.php' || u.username || u.password || u.hash) return null;
    const token = u.searchParams.get('checkin');
    if ([...u.searchParams.keys()].length !== 1 || !/^[a-f0-9]{64}$/.test(token || '')) return null;
    return token;
  } catch { return null; }
}
if (typeof document !== 'undefined') {
  const video = document.getElementById('door-video');
  const start = document.getElementById('door-start');
  const stopButton = document.getElementById('door-stop');
  const status = document.getElementById('door-status');
  let stream, timer, running = false;
  function stop() {
    running = false; clearTimeout(timer);
    if (stream) stream.getTracks().forEach(track => track.stop());
    stream = null; video.srcObject = null; video.hidden = true;
    start.disabled = false; stopButton.hidden = true;
  }
  start.addEventListener('click', async () => {
    start.disabled = true;
    try {
      if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('camera');
      let detector = null;
      try { if(window.BarcodeDetector && (await BarcodeDetector.getSupportedFormats()).includes('qr_code')) detector = new BarcodeDetector({formats:['qr_code']}); } catch {}
      if (!detector && typeof window.jsQR !== 'function') {
        status.textContent = 'Este navegador não tem leitor QR integrado. Use a câmera do celular para abrir o link ou cole o código no campo acima.';
        start.disabled = false; return;
      }
      const canvas=document.createElement('canvas'), context=canvas.getContext('2d',{willReadFrequently:true});
      stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
      if(document.hidden){stop();return;}
      video.srcObject = stream; video.hidden = false; await video.play();
      running = true; stopButton.hidden = false;
      status.textContent = 'Aponte para o QR do ingresso. A leitura só consulta; a entrada exige confirmação.';
      async function scan() {
        if (!running) return;
        try {
          let found=[];
          if(video.readyState>=2){
            if(detector){try{found=await detector.detect(video);}catch{if(typeof window.jsQR==='function')detector=null;else throw new Error('decode');}}
            if(!detector){
              const scale=Math.min(1,800/video.videoWidth);canvas.width=Math.round(video.videoWidth*scale);canvas.height=Math.round(video.videoHeight*scale);
              context.drawImage(video,0,0,canvas.width,canvas.height);const pixels=context.getImageData(0,0,canvas.width,canvas.height);
              const decoded=window.jsQR(pixels.data,pixels.width,pixels.height,{inversionAttempts:'dontInvert'});if(decoded)found=[{rawValue:decoded.data}];
            }
          }
          if (!running) return;
          for (const code of found) {
            const token = admissionToken(code.rawValue, location.origin);
            if (token) { stop(); location.assign('admin.php?checkin=' + token); return; }
            status.textContent = 'Este QR não é um ingresso válido deste site. Aponte para o QR de entrada, não o Pix.';
          }
          timer = setTimeout(scan, 300);
        } catch { stop(); status.textContent = 'Não foi possível ler a câmera. Tente novamente ou use o código manual.'; }
      }
      scan();
    } catch { stop(); status.textContent = 'Câmera indisponível ou permissão negada. Confira a permissão do navegador ou use o código manual.'; }
  });
  stopButton.addEventListener('click', () => {stop();status.textContent='Câmera desligada.';});
  window.addEventListener('pagehide', stop);
  document.addEventListener('visibilitychange', () => {if(document.hidden)stop();});
}
