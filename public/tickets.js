'use strict';
document.querySelectorAll('.ticket-qr').forEach(box=>{
  try {const qr=qrcode(0,'M');qr.addData(box.dataset.code,'Byte');qr.make();box.innerHTML=qr.createSvgTag({cellSize:4,margin:16,scalable:true});const svg=box.querySelector('svg');svg.setAttribute('role','img');svg.setAttribute('aria-label','QR Code de entrada do ingresso');svg.style.maxWidth='300px';}
  catch {box.textContent='Não foi possível desenhar o QR Code. Recarregue a página com internet ou procure a Gestão.';}
});
