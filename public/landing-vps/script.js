const C = window.VPS;
const kz = n => Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".") + " Kz";
const $ = s => document.querySelector(s);
function precoPlano(id, ciclo) {
  const p = C.planos[id], c = C.ciclos[ciclo];
  const cheio = p.preco * c.meses, total = cheio * (1 - c.desc);
  return { cheio, total, mes: total / c.meses };
}
window.__vps = { kz, precoPlano };

if ($("#plans")) {
  let ciclo = "mensal";
  try { ciclo = localStorage.getItem("vps_ciclo") || "mensal"; } catch (e) {}
  if (!C.ciclos[ciclo]) ciclo = "mensal";
  const render = () => {
    $("#ciclos").innerHTML = Object.entries(C.ciclos).map(([k, c]) =>
      `<button class="${k === ciclo ? "on" : ""}" data-c="${k}">${c.nome}</button>`).join("");
    $("#plans").innerHTML = Object.entries(C.planos).map(([id, p]) => {
      const pr = precoPlano(id, ciclo), hot = id === "pro";
      return `<article class="plan ${hot ? "hot" : ""}">${hot ? '<span class="tag">⭐ MAIS VENDIDO</span>' : ""}
        <h3>${p.nome}</h3>
        <div class="price">${kz(pr.mes)}<small>/mês</small></div>
        <div class="old">${C.ciclos[ciclo].desc ? kz(p.preco) + "/mês" : ""}</div>
        <div class="spec"><span>CPU<b>${p.cpu}</b></span><span>Memória<b>${p.ram}</b></span><span>Disco<b>${p.disco}</b></span><span>Tráfego<b>${p.banda}</b></span></div>
        <ul>${p.itens.map(i => `<li>${i}</li>`).join("")}</ul>
        <a class="btn full" href="checkout.html?plano=${id}&ciclo=${ciclo}">Comprar ${p.nome}</a></article>`;
    }).join("");
  };
  $("#ciclos").addEventListener("click", e => {
    const b = e.target.closest("button"); if (!b) return;
    ciclo = b.dataset.c;
    try { localStorage.setItem("vps_ciclo", ciclo); } catch (x) {}
    render();
  });
  render();
  const wa = "https://wa.me/" + C.whatsapp + "?text=" + encodeURIComponent("Olá! Tenho interesse num VPS.");
  $("#waTop").href = wa; $("#waFloat").href = wa;
  $("#ano").textContent = new Date().getFullYear();
}
