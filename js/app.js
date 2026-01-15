console.log("app.js загружен!");

// Простой тест формы
document.addEventListener("DOMContentLoaded", () => {
    console.log("DOM загружен!");
    
    const form = document.getElementById("waitlistForm");
    if (form) {
        console.log("Форма найдена!");
        
        form.addEventListener("submit", function(e) {
            e.preventDefault(); // Это ОЧЕНЬ важно!
            console.log("Форма отправлена!");
            alert("Тест: форма работает!");
        });
    }
});
  
  // ---------- WAITLIST FORM (БД на reg.ru) ----------
  const form = document.getElementById("waitlistForm");
  const note = document.getElementById("formNote");
  const emailInput = document.getElementById("email");

  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const email = (emailInput?.value || "").trim();
      
      // Валидация
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        note.textContent = "Похоже, email введён некорректно.";
        note.style.color = '#ef4444';
        return;
      }

      // Показываем статус отправки
      note.textContent = "Сохраняем…!!!";
      note.style.color = '';

      try {
        // Отправляем на ваш subscribe.php
        const res = await fetch("/subscribe.php", {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
          },
          body: JSON.stringify({ email })
        });

        // Парсим ответ
        const data = await res.json();
        
        console.log("Ответ от сервера:", data); // для отладки

        if (data.ok) {
          // УСПЕХ
          note.textContent = "✓ Готово! Email сохранён. Мы напишем, когда откроем ранний доступ.";
          note.style.color = '#10b981';
          form.reset();
          
          // Через 5 секунд скрыть сообщение
          setTimeout(() => {
            note.textContent = '';
          }, 5000);
        } else {
          // ОШИБКА С СЕРВЕРА
          note.textContent = "✗ " + (data.error || "Не получилось сохранить. Попробуй ещё раз.");
          note.style.color = '#ef4444';
        }
      } catch (err) {
        console.error("Ошибка отправки:", err);
        note.textContent = "✗ Ошибка сети. Проверь интернет и попробуй ещё раз.";
        note.style.color = '#ef4444';
      }
    });
  }

  // ---------- STATUSBAR TIME ----------
  function setSBTime() {
    const d = new Date();
    const hh = String(d.getHours()).padStart(2, "0");
    const mm = String(d.getMinutes()).padStart(2, "0");
    const el = document.getElementById("sbTime");
    if (el) el.textContent = `${hh}:${mm}`;
  }
  setSBTime();
  setInterval(setSBTime, 15000);

  // ---------- DEMO NAV ----------
  const screens = Array.from(document.querySelectorAll(".demo-screen"));
  const titleEl = document.getElementById("demoTitle");
  const chipEl = document.getElementById("demoChip");
  const prevBtn = document.getElementById("prevDemo");
  const nextBtn = document.getElementById("nextDemo");
  const dotsWrap = document.getElementById("demoDots");

  const overlay = document.getElementById("overlay");
  const bar = document.getElementById("bar");
  const overlayText = document.getElementById("overlayText");

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
      void el.offsetWidth; // force reflow
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
      { p: 100, t: "Собираем офисный образ и ссылки на товары…" }
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
    // After screen 1 -> show analysis overlay
    if (current === 0) {
      fakeAnalyzeThenGoNext();
      return;
    }
    if (current === screens.length - 1) showScreen(0);
    else showScreen(current + 1);
  });

  showScreen(0);

  // ---------- FIXED DEMO DATA ----------
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
  setText("rWhy", "Холодная палитра и средне-высокий контраст усиливают черты лица, а прямые линии аккуратно 'собирают' силуэт.");
;