// FILE: /js/upload.js
// ВАЖНО: убираем scrollIntoView, и держим scrollY при переключениях.

document.addEventListener("DOMContentLoaded", () => {
  console.log("UPLOAD BUILD MARK:", "2026-01-18__v5");

  const byId = (id) => document.getElementById(id);

  const uploadStage = byId("uploadStage");
  const loadingStage = byId("loadingStage");
  const resultStage = byId("resultStage");
  const uploadLegal = byId("uploadLegal");

  const form = byId("uploadForm");
  const email = byId("uploadEmail");
  const faceInput = byId("faceInput");
  const fullInput = byId("fullInput");
  const faceChip = byId("faceChip");
  const fullChip = byId("fullChip");
  const sendBtn = byId("sendToAiBtn");
  const note = byId("uploadNote");

  const aiTitle = byId("aiTitle");
  const aiReason = byId("aiReason");
  const aiBullets = byId("aiBullets");
  const aiReset = byId("aiReset");

  const qs = new URLSearchParams(location.search);
  const MOCK = qs.has("mock");          // /upload.html?mock
  const FORCE_LOADING = qs.has("loading"); // /upload.html?loading


  const missing = [];
  [
    ["uploadStage", uploadStage],
    ["loadingStage", loadingStage],
    ["resultStage", resultStage],
    ["uploadLegal", uploadLegal],
    ["uploadForm", form],
    ["uploadEmail", email],
    ["faceInput", faceInput],
    ["fullInput", fullInput],
    ["faceChip", faceChip],
    ["fullChip", fullChip],
    ["sendToAiBtn", sendBtn],
    ["uploadNote", note],
    ["aiTitle", aiTitle],
    ["aiReason", aiReason],
    ["aiBullets", aiBullets],
    ["aiReset", aiReset],
  ].forEach(([name, el]) => {
    if (!el) missing.push(name);
  });

  if (missing.length) {
    console.error("upload.js: missing DOM elements:", missing);
    return;
  }

  const setNote = (text, ok) => {
    note.textContent = text || "";
    note.className = ok === true ? "note ok" : ok === false ? "note err" : "note";
  };

  const hasFile = (input) => Boolean(input?.files && input.files.length > 0);

  const setChip = (chipEl, ok) => {
    chipEl.classList.toggle("ok", ok);
    chipEl.textContent = ok ? "Загружено" : "Не загружено";
  };

  const canSubmit = () => email.checkValidity() && hasFile(faceInput) && hasFile(fullInput);

  const update = () => {
    setChip(faceChip, hasFile(faceInput));
    setChip(fullChip, hasFile(fullInput));
    sendBtn.disabled = !canSubmit();
  };

  const escapeHtml = (s) =>
    String(s).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[c]);

  const showStage = (name) => {
    uploadStage.style.display = name === "upload" ? "block" : "none";
    loadingStage.style.display = name === "loading" ? "block" : "none";
    resultStage.style.display = name === "result" ? "block" : "none";
    uploadLegal.style.display = name === "upload" ? "" : "none";
  };

  const showStageKeepScroll = (name) => {
    const y = window.scrollY;
    showStage(name);
    requestAnimationFrame(() => window.scrollTo({ top: y }));
  };

  const renderResult = ({ type, reason, bullets }) => {
    aiTitle.textContent = `Твой типаж — ${type || "—"}`;
    aiReason.textContent = reason || "";

    aiBullets.innerHTML = "";
    (Array.isArray(bullets) ? bullets : []).slice(0, 8).forEach((b) => {
      const span = document.createElement("span");
      span.className = "achip";
      span.innerHTML = `<span>${escapeHtml(String(b))}</span>`;
      aiBullets.appendChild(span);
    });

    // ❌ УБРАЛИ scrollIntoView (он и "съезжал" страницу)
    showStageKeepScroll("result");
  };

  faceInput.addEventListener("change", update);
  fullInput.addEventListener("change", update);
  email.addEventListener("input", update);

  aiReset.addEventListener("click", () => location.reload());

  update();
  showStage("upload");

  if (FORCE_LOADING) {
  showStage("loading");
}

  if (MOCK) {
    // чтобы можно было нажать кнопку без файлов
    sendBtn.disabled = false;

    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      showStage("loading");
      setNote("", null);

      await new Promise((r) => setTimeout(r, 1600)); // имитация ожидания

      renderResult({
        type: "Муза",
        reason: "Мок-результат для локального теста интерфейса.",
        bullets: ["Пункт 1", "Пункт 2", "Пункт 3"],
      });
    }, { once: true });
  }


  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!canSubmit()) return;

    sendBtn.disabled = true;
    setNote("", null);
    showStageKeepScroll("loading");

    try {
      const fd = new FormData(form);
      const res = await fetch("/api/submit.php", { method: "POST", body: fd });
      const rawText = await res.text();

      let data = null;
      try {
        data = JSON.parse(rawText);
      } catch {
        throw new Error("Сервер вернул не JSON: " + rawText.slice(0, 120));
      }

      if (!res.ok) throw new Error(`HTTP ${res.status}: ${rawText.slice(0, 160)}`);
      if (!data?.ok) throw new Error(data?.error || "Ошибка сервера");

      const r = data.result;
      if (!r || !r.type || !r.reason) throw new Error("AI вернул пустой результат");

      renderResult({
        type: String(r.type).trim(),
        reason: String(r.reason).trim(),
        bullets: Array.isArray(r.bullets) ? r.bullets : [],
      });
    } catch (err) {
      showStageKeepScroll("upload");
      setNote(`Ошибка: ${err.message}`, false);
      sendBtn.disabled = !canSubmit();
    }
  });
});

