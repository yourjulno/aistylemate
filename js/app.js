// FILE: /js/app.js
// Keep this file in /js/app.js (NOT embedded in HTML).

console.log("APP BUILD MARK:", "2026-01-18__upload_backend_v1");

document.addEventListener("DOMContentLoaded", () => {
  initWaitlist();       // index.html waitlist
  initUploadForm();     // upload.html
});

function initWaitlist() {
  const form = document.getElementById("waitlistForm");
  const note = document.getElementById("formNote");
  if (!form || !note) return;

  let inflight = false;

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

  document.addEventListener(
    "submit",
    async (e) => {
      const target = e.target;
      if (!(target instanceof HTMLFormElement)) return;
      if (target.id !== "waitlistForm") return;

      e.preventDefault();
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

function initUploadForm() {
  // Only on upload.html
  if (!document.body.classList.contains("page-upload")) return;

  const form = document.getElementById("uploadForm");
  const email = document.getElementById("uploadEmail");
  const faceInput = document.getElementById("faceInput");
  const fullInput = document.getElementById("fullInput");
  const faceChip = document.getElementById("faceChip");
  const fullChip = document.getElementById("fullChip");
  const sendBtn = document.getElementById("sendToAiBtn");
  const note = document.getElementById("uploadNote");

  if (!form || !email || !faceInput || !fullInput || !faceChip || !fullChip || !sendBtn || !note) return;

  const setChip = (chipEl, file) => {
    if (!file) {
      chipEl.classList.remove("ok");
      chipEl.textContent = "Не загружено";
      return;
    }
    chipEl.classList.add("ok");
    chipEl.textContent = "Загружено";
  };

  const canSubmit = () => {
    const okEmail = email.checkValidity();
    const okFiles = Boolean(faceInput.files?.[0] && fullInput.files?.[0]);
    return okEmail && okFiles;
  };

  const updateUi = () => {
    setChip(faceChip, faceInput.files?.[0] ?? null);
    setChip(fullChip, fullInput.files?.[0] ?? null);
    sendBtn.disabled = !canSubmit();
  };

  faceInput.addEventListener("change", updateUi);
  fullInput.addEventListener("change", updateUi);
  email.addEventListener("input", updateUi);

  updateUi();

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!canSubmit()) return;

    note.className = "note";
    note.textContent = "Отправляем в AI…";
    sendBtn.disabled = true;

    try {
      const fd = new FormData(form); // email + face + full
      const res = await fetch("/api/submit.php", { method: "POST", body: fd });
      const data = await res.json().catch(() => null);

      if (!res.ok || !data?.ok) throw new Error(data?.error || "Ошибка сервера");

      note.className = "note ok";
      note.textContent = "Готово ✅ Ответ AI получен (смотри console).";
      console.log("AI:", data.aiText);
    } catch (err) {
      note.className = "note err";
      note.textContent = `Ошибка: ${err.message}`;
    } finally {
      sendBtn.disabled = false;
    }
  });
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function setNote(noteEl, text, color) {
  noteEl.textContent = text;
  noteEl.style.color = color || "";
}
