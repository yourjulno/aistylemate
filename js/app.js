// /js/app.js
console.log("APP BUILD MARK:", "2026-01-15__clean_v2");

document.addEventListener("DOMContentLoaded", () => {
  console.log("=== STYLEMATE APP START ===");

  initWaitlist();
  initStatusbarTime();
  initDemoNav();
  initFixedDemoData();
});

function initWaitlist() {
  const form = document.getElementById("waitlistForm");
  const note = document.getElementById("formNote");

  if (!form || !note) {
    console.error("❌ waitlistForm/formNote не найдены");
    return;
  }

  let inflight = false;

  // Реакция "при вводе" (то, что ты ожидаешь)
  const emailInput = form.querySelector("#email");
  if (emailInput) {
    emailInput.addEventListener("input", () => {
      note.textContent = "";
      note.style.color = "";
    });

    emailInput.addEventListener("blur", () => {
      const email = emailInput.value.trim();
      if (!email) return;
      if (!isValidEmail(email)) setNote(note, "✗ Введите корректный email", "#ef4444");
    });
  }

  // Один обработчик submit. Важно: НЕ stopPropagation — иначе ломаем другие листенеры.
  document.addEventListener(
    "submit",
    async (e) => {
      const target = e.target;
      if (!(target instanceof HTMLFormElement)) return;
      if (target.id !== "waitlistForm") return;

      e.preventDefault(); // убираем перезагрузку
      console.log("WAITLIST submit ✅");

      if (inflight) return;
      inflight = true;

      const submitBtn = target.querySelector('button[type="submit"]');
      const input = target.querySelector("#email");
      const email = (input?.value || "").trim();

      try {
        if (!email || !isValidEmail(email)) {
          setNote(note, "✗ Введите корректный email", "#ef4444");
          return;
        }

        setNote(note, "Отправка...", "");
        submitBtn?.setAttribute("disabled", "disabled");

        const res = await fetch("/subscribe.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email }),
          cache: "no-store",
        });

        const json = await res.json().catch(() => null);
        console.log("subscribe.php:", res.status, json);

        if (res.ok && json?.ok) {
          setNote(note, "✓ Спасибо! Вы в списке ожидания.", "#10b981");
          if (input) input.value = "";
          setTimeout(() => (note.textContent = ""), 5000);
        } else {
          setNote(note, "✗ " + (json?.error || "Ошибка сервера"), "#ef4444");
        }
      } catch (err) {
        console.error(err);
        setNote(note, "✗ Ошибка сети", "#ef4444");
      } finally {
        inflight = false;
        submitBtn?.removeAttribute("disabled");
      }
    },
    true
  );
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function setNote(noteEl, text, color) {
  noteEl.textContent = text;
  noteEl.style.color = color || "";
}

function initStatusbarTime() {
  function setSBTime() {
    const d = new Date();
    const hh = String(d.getHours()).padStart(2, "0");
    const mm = String(d.getMinutes()).padStart(2, "0");
    const el = document.getElementById("sbTime");
    if (el) el.textContent = `${hh}:${mm}`;
  }
  setSBTime();
  setInterval(setSBTime, 15000);
}

function initDemoNav() {
  const screens = Array.from(document.querySelectorAll(".demo-screen"));
  const titleEl = document.getElementById("demoTitle");
  const chipEl = document.getElementById("demoChip");
  const prevBtn = document.getElementById("prevDemo");
  const nextBtn = document.getElementById("nextDemo");
  const dotsWrap = document.getElementById("demoDots");

  const overlay = document.getElementById("overlay");
  const bar = document.getElementById("bar");
  const overlayText = document.getElementById("overlayText");

  if (!screens.length) return;

  let current = 0;

  const dots = screens.map(() => {
    const d = document.createElement("span");
    d.className = "dot2";
    dotsWrap?.appendChild(d);
    return d;
  });

  function retriggerReveal() {
    document.querySelectorAll(".demo-screen.active .reveal").forEach((el) => {
      el.classList.remove("reveal");
      void el.offsetWidth;
      el.classList.add("reveal");
    });
  }

  function showScreen(i) {
    current = Math.max(0, Math.min(i, screens.length - 1));
    screens.forEach((s, idx) => s.classList.toggle("active", idx === current));
    dots.forEach((d, idx) => d.classList.toggle("active", idx === current));

    if (titleEl) titleEl.textContent = screens[current]?.dataset?.title || `Шаг ${current + 1}`;
    if (chipEl) chipEl.textContent = screens[current]?.dataset?.chip || "demo";
    if (prevBtn) prevBtn.disabled = current === 0;

    retriggerReveal();
  }

  function fakeAnalyzeThenGoNext() {
    if (!overlay || !bar || !overlayText) {
      showScreen(current + 1);
      return;
    }

    overlay.classList.add("active");
    bar.style.width = "0%";

    const steps = [
      { p: 25, t: "Определяем подтон и контраст по фото лица…" },
      { p: 55, t: "Считываем силуэт и пропорции по фото в полный рост…" },
      { p: 80, t: "Подбираем палитру и базовые линии…" },
      { p: 100, t: "Собираем офисный образ и ссылки на товары…" },
    ];

    let k = 0;
    const timer = setInterval(() => {
      overlayText.textContent = steps[k].t;
      bar.style.width = steps[k].p + "%";
      k++;
      if (k >= steps.length) {
        clearInterval(timer);
        setTimeout(() => {
          overlay.classList.remove("active");
          showScreen(current + 1);
        }, 400);
      }
    }, 420);
  }

  prevBtn?.addEventListener("click", () => showScreen(current - 1));
  nextBtn?.addEventListener("click", () => {
    if (current === 0) return fakeAnalyzeThenGoNext();
    if (current === screens.length - 1) showScreen(0);
    else showScreen(current + 1);
  });

  showScreen(0);
}

function initFixedDemoData() {
  const setText = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  };

  const content = document.querySelector(".appcontent");
  if (content) content.scrollTop = 0;

  setText("rUndertone", "холодный");
  setText("rContrast", "средний–высокий");
  setText("rBody", "баланс");
  setText("rSilhouette", "прямой");
  setText(
    "rWhy",
    "Холодная палитра и средне-высокий контраст усиливают черты лица, а прямые линии аккуратно 'собирают' силуэт."
  );
}
