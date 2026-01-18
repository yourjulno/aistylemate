// FILE: /js/upload.js
"use strict";

document.addEventListener("DOMContentLoaded", () => {
  if (!document.body.classList.contains("page-upload")) return;

  const form = document.getElementById("uploadForm");
  const email = document.getElementById("uploadEmail");
  const faceInput = document.getElementById("faceInput");
  const fullInput = document.getElementById("fullInput");
  const faceChip = document.getElementById("faceChip");
  const fullChip = document.getElementById("fullChip");
  const facePreview = document.getElementById("facePreview");
  const fullPreview = document.getElementById("fullPreview");
  const sendBtn = document.getElementById("sendToAiBtn");
  const note = document.getElementById("uploadNote");

  if (!form || !email || !faceInput || !fullInput || !faceChip || !fullChip || !sendBtn) return;

  let faceUrl = null;
  let fullUrl = null;

  const revoke = (url) => {
    if (url) URL.revokeObjectURL(url);
  };

  const setChip = (chipEl, file) => {
    if (!file) {
      chipEl.classList.remove("ok");
      chipEl.textContent = "Не загружено";
      return;
    }
    chipEl.classList.add("ok");
    chipEl.textContent = "Загружено";
  };

  const setPreview = (imgEl, file, kind) => {
    if (!imgEl) return;

    if (!file) {
      imgEl.removeAttribute("src");
      imgEl.style.display = "none";
      return;
    }

    if (kind === "face") {
      revoke(faceUrl);
      faceUrl = URL.createObjectURL(file);
      imgEl.src = faceUrl;
    } else {
      revoke(fullUrl);
      fullUrl = URL.createObjectURL(file);
      imgEl.src = fullUrl;
    }

    imgEl.style.display = "block";
  };

  const canSubmit = () => {
    const okEmail = email.checkValidity();
    const okFiles = Boolean(faceInput.files?.[0] && fullInput.files?.[0]);
    return okEmail && okFiles;
  };

  const setNote = (text, ok) => {
    note.textContent = text || "";
    note.className = ok ? "note ok" : "note err";
  };

  const updateUi = () => {
    const faceFile = faceInput.files?.[0] ?? null;
    const fullFile = fullInput.files?.[0] ?? null;

    setChip(faceChip, faceFile);
    setChip(fullChip, fullFile);

    setPreview(facePreview, faceFile, "face");
    setPreview(fullPreview, fullFile, "full");

    sendBtn.disabled = !canSubmit();
    if (!canSubmit()) note.textContent = "";
  };

  faceInput.addEventListener("change", updateUi);
  fullInput.addEventListener("change", updateUi);
  email.addEventListener("input", updateUi);

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!canSubmit()) return;

    sendBtn.disabled = true;
    setNote("Отправляем в AI…", true);

    try {
      const fd = new FormData(form); // email + face + full
      const res = await fetch("api/submit.php", { method: "POST", body: fd });
      const data = await res.json().catch(() => null);

      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Ошибка сервера");
      }

      setNote("Готово ✅ Ответ AI получен. (см. консоль)", true);
      console.log("AI:", data.aiText);
    } catch (err) {
      setNote(`Ошибка: ${err.message}`, false);
    } finally {
      sendBtn.disabled = false;
    }
  });

  window.addEventListener("beforeunload", () => {
    revoke(faceUrl);
    revoke(fullUrl);
  });

  updateUi();
});
