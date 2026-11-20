(() => {
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* Fundo: rede de partículas interativa */
  const cv = $("#bg");
  if (cv && !reduce) {
    const x = cv.getContext("2d"); let W, H, pts = [], m = { x: -999, y: -999 }; const par = cv.parentElement;
    const size = () => { W = cv.width = par.offsetWidth; H = cv.height = par.offsetHeight; pts = Array.from({ length: Math.min(90, W / 14 | 0) }, () => ({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - .5) * .4, vy: (Math.random() - .5) * .4 })); };
    size(); addEventListener("resize", size);
    addEventListener("mousemove", e => { const r = cv.getBoundingClientRect(); m.x = e.clientX - r.left; m.y = e.clientY - r.top; });
    (function loop() {
      x.clearRect(0, 0, W, H);
      for (const p of pts) {
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > W) p.vx *= -1; if (p.y < 0 || p.y > H) p.vy *= -1;
        const dx = p.x - m.x, dy = p.y - m.y, d = Math.hypot(dx, dy);
        if (d < 140) { p.x += dx / d * 1.2; p.y += dy / d * 1.2; }
        x.fillStyle = "rgba(95,201,216,.8)"; x.beginPath(); x.arc(p.x, p.y, 1.6, 0, 7); x.fill();
      }
      for (let i = 0; i < pts.length; i++) for (let j = i + 1; j < pts.length; j++) {
        const d = Math.hypot(pts[i].x - pts[j].x, pts[i].y - pts[j].y);
        if (d < 130) { x.strokeStyle = `rgba(143,176,255,${.4 * (1 - d / 130)})`; x.beginPath(); x.moveTo(pts[i].x, pts[i].y); x.lineTo(pts[j].x, pts[j].y); x.stroke(); }
      }
      requestAnimationFrame(loop);
    })();
  }

  /* Luz que segue o rato */
  const g = $(".glow");
  addEventListener("mousemove", e => { if (g) { g.style.left = e.clientX + "px"; g.style.top = e.clientY + "px"; } });

  /* Barra de progresso + nav compacta */
  const pr = $(".progress"), nav = $(".nav");
  addEventListener("scroll", () => {
    const h = document.documentElement; pr.style.width = (scrollY / (h.scrollHeight - innerHeight) * 100) + "%";
    nav.classList.toggle("small", scrollY > 40);
  }, { passive: true });

  /* Reveal ao fazer scroll */
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); if (e.target.dataset.count) count(e.target); } }), { threshold: .15 });
  const watch = () => $$(".rv:not(.in)").forEach(el => io.observe(el));

  /* Contadores */
  function count(el) {
    const b = $("b", el), to = parseFloat(b.dataset.to), dec = b.dataset.dec | 0, suf = b.dataset.suf || "";
    if (isNaN(to)) return; const t0 = performance.now();
    (function s(t) { const k = Math.min(1, (t - t0) / 1600), v = to * (1 - Math.pow(1 - k, 3)); b.textContent = v.toFixed(dec).replace(".", ",") + suf; if (k < 1) requestAnimationFrame(s); })(t0);
  }

  /* Tilt 3D + spotlight (delegado, funciona com cartões dinâmicos) */
  document.addEventListener("mousemove", e => {
    const c = e.target.closest(".plan,.feat"); if (!c || matchMedia("(hover:none)").matches) return;
    const r = c.getBoundingClientRect(), px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height;
    c.style.setProperty("--mx", px * 100 + "%"); c.style.setProperty("--my", py * 100 + "%");
    c.style.transform = `perspective(900px) rotateX(${(.5 - py) * 8}deg) rotateY(${(px - .5) * 10}deg) translateY(-6px)`;
  });
  document.addEventListener("mouseout", e => { const c = e.target.closest(".plan,.feat"); if (c && !c.contains(e.relatedTarget)) c.style.transform = ""; });

  /* Botões magnéticos */
  document.addEventListener("mousemove", e => {
    const b = e.target.closest(".btn:not(.full)"); if (!b) return;
    const r = b.getBoundingClientRect(); b.style.transform = `translate(${(e.clientX - r.left - r.width / 2) * .15}px,${(e.clientY - r.top - r.height / 2) * .25}px)`;
  });
  document.addEventListener("mouseout", e => { const b = e.target.closest(".btn"); if (b) b.style.transform = ""; });

  /* Efeito de máquina de escrever no título */
  const ty = $(".type");
  if (ty) {
    const words = ["potentes", "rápidos", "seguros", "escaláveis"]; let i = 0, j = 0, del = false;
    (function t() {
      const w = words[i]; ty.textContent = w.slice(0, j);
      if (!del && j === w.length) { del = true; return setTimeout(t, 1600); }
      if (del && j === 0) { del = false; i = (i + 1) % words.length; }
      j += del ? -1 : 1; setTimeout(t, del ? 45 : 95);
    })();
  }

  /* Terminal animado */
  const te = $("#term");
  if (te) {
    const lines = ["<b>$</b> ssh root@vps.ecomulti.ao", "Ligado ✓ Ubuntu 24.04 LTS", "<b>$</b> apt install nginx docker -y", "<u>✔</u> Instalação concluída em 8s", "<b>$</b> uptime", "<u>99,9%</u> online · carga 0.02", "<b>$</b> _"];
    let li = 0; const out = [];
    (function n() { out.push(lines[li]); te.innerHTML = out.join("\n"); li++; if (li >= lines.length) { li = 0; return setTimeout(() => { out.length = 0; n(); }, 3500); } setTimeout(n, 900); })();
  }

  /* Efeito "tick" no preço ao trocar de ciclo */
  const pl = $("#plans");
  if (pl) new MutationObserver(() => { $$(".price", pl).forEach(p => { p.classList.remove("tick"); void p.offsetWidth; p.classList.add("tick"); }); }).observe(pl, { childList: true });

  watch(); addEventListener("load", watch);
  setTimeout(() => $$(".rv").forEach(el => { if (el.getBoundingClientRect().top < innerHeight) el.classList.add("in"); }), 150);
})();
