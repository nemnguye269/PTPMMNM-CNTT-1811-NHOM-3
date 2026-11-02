(() => {
  "use strict";
  const BASE = window.GTFS_BASE || "";
  const money = (n) => new Intl.NumberFormat("vi-VN").format(n) + " ₫";
  const dmy = (d) => d.toLocaleDateString("vi-VN", { day: "2-digit", month: "2-digit", year: "numeric" });

  // Nút "Chép"
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-copy]");
    if (!btn || !navigator.clipboard) return;
    navigator.clipboard.writeText(btn.dataset.copy).then(() => {
      const old = btn.textContent;
      btn.textContent = "Đã chép";
      setTimeout(() => (btn.textContent = old), 1200);
    });
  });

  // ---------- Trang mua vé: tóm tắt đơn cập nhật tức thì ----------
  const form = document.getElementById("buyForm");
  if (form) {
    const radios = [...form.querySelectorAll('input[name="type"]')];
    const qty = document.getElementById("qty");
    const from = document.getElementById("validFrom");
    const routeSel = document.getElementById("routeSelect");
    const routeField = document.getElementById("routeField");
    const $ = (id) => document.getElementById(id);

    const render = () => {
      const r = radios.find((x) => x.checked) || radios[0];
      const price = Number(r.dataset.price);
      const max = Number(r.dataset.max);
      const days = Number(r.dataset.days);
      const needsRoute = r.dataset.route === "1";

      radios.forEach((x) => x.closest(".type-card").classList.toggle("on", x.checked));
      routeField.hidden = !needsRoute;
      routeSel.required = needsRoute;

      qty.max = String(max);
      let n = Math.max(1, Math.min(max, parseInt(qty.value, 10) || 1));
      qty.value = String(n);

      $("sumType").textContent = r.dataset.name;
      $("sumQty").textContent = n + " × " + money(price);
      $("sumTotal").textContent = money(price * n);
      $("sumRoute").textContent = needsRoute
        ? (routeSel.value ? routeSel.options[routeSel.selectedIndex].text : "Chưa chọn tuyến")
        : "Mọi tuyến";

      if (from.value) {
        const start = new Date(from.value + "T00:00:00");
        const end = new Date(start);
        end.setDate(end.getDate() + days - 1);
        $("sumValid").textContent = days > 1 ? dmy(start) + " – " + dmy(end) : dmy(start);
      }
    };

    form.addEventListener("input", render);
    form.addEventListener("change", render);
    $("qtyMinus").addEventListener("click", () => { qty.value = String((parseInt(qty.value, 10) || 1) - 1); render(); });
    $("qtyPlus").addEventListener("click", () => { qty.value = String((parseInt(qty.value, 10) || 1) + 1); render(); });
    render();
  }

  // ---------- Trang thanh toán: đếm ngược + tự kiểm tra đã nhận tiền ----------
  const box = document.getElementById("payBox");
  if (box) {
    const code = box.dataset.code;
    const expires = Number(box.dataset.expires) * 1000;
    const label = document.getElementById("countdown");
    const state = document.getElementById("payState");
    let done = false;

    const tick = () => {
      const left = Math.max(0, Math.floor((expires - Date.now()) / 1000));
      if (label) label.textContent = String(Math.floor(left / 60)).padStart(2, "0") + ":" + String(left % 60).padStart(2, "0");
      if (left === 0 && !done) { done = true; location.reload(); }
    };
    tick();
    setInterval(tick, 1000);

    const poll = async () => {
      if (done) return;
      try {
        const res = await fetch(BASE + "/api/tickets/status?code=" + encodeURIComponent(code), { headers: { Accept: "application/json" }, cache: "no-store" });
        const data = await res.json();
        if (data.paid) {
          done = true;
          if (state) { state.classList.add("ok"); state.textContent = "Đã nhận thanh toán! Đang mở vé…"; }
          setTimeout(() => (location.href = BASE + box.dataset.view), 700);
        } else if (data.state && data.state !== "pending") {
          done = true;
          location.reload();
        }
      } catch (_) { /* mạng chập chờn: thử lại ở lần sau */ }
    };
    if (state) setInterval(poll, 3000); // chỉ poll khi có QR thật (chế độ chưa cấu hình không có dòng trạng thái)
  }
})();
