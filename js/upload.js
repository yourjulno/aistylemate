// FILE: /js/upload.js
// BUILD: v18 (Fix mobile outfits: full MUST be PNG + visible errors on event screen + mobile-safe PNG sizing)

document.addEventListener("DOMContentLoaded", () => {
  console.log("UPLOAD BUILD MARK:", "2026-01-20__v18");

  const byId = (id) => document.getElementById(id);

  // ===== Config =====
  const qs = new URLSearchParams(location.search);
  const MOCK = qs.has("mock"); // /upload.html?mock
  const FORCE_LOADING = qs.has("loading"); // /upload.html?loading
  const WORKER_BASE = "https://aistylemate.ru/api";

  const isMobile =
    /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
      navigator.userAgent
    );
  console.log("Device detection:", isMobile ? "Mobile" : "Desktop", navigator.userAgent);

  const OUTFIT_SIZE = isMobile ? 768 : 1024;
  const OUTFIT_COUNT = 1;

  // ===== Stages =====
  const uploadStage = byId("uploadStage");
  const loadingStage = byId("loadingStage");
  const resultStage = byId("resultStage");
  const outfitsStage = byId("outfitsStage");
  const uploadLegal = byId("uploadLegal");

  // ===== Form =====
  const form = byId("uploadForm");
  const email = byId("uploadEmail");
  const faceInput = byId("faceInput");
  const fullInput = byId("fullInput");
  const faceChip = byId("faceChip");
  const fullChip = byId("fullChip");
  const sendBtn = byId("sendToAiBtn");
  const note = byId("uploadNote");

  // ===== Result =====
  const aiTitle = byId("aiTitle");
  const aiReason = byId("aiReason");
  const aiBullets = byId("aiBullets");
  const aiReset = byId("aiReset");

  // ===== Event UI =====
  const toEventBtn = byId("toEventBtn");
  const toEventLabel = byId("toEventLabel");
  const backFromEventBtn = byId("backFromEventBtn");
  const eventSection = byId("eventSection");
  const otherWrap = byId("otherWrap");
  const otherEventInput = byId("otherEventInput");
  const genLooksBtn = byId("genLooksBtn");

  // ===== Outfits =====
  const outfitsGrid = byId("outfitsGrid");
  const outfitsSub = byId("outfitsSub");
  const backToEventBtn = byId("backToEventBtn");

  // ===== Required checks =====
  const missing = [];
  [
    ["uploadStage", uploadStage],
    ["loadingStage", loadingStage],
    ["resultStage", resultStage],
    ["outfitsStage", outfitsStage],
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
    ["toEventBtn", toEventBtn],
    ["toEventLabel", toEventLabel],
    ["backFromEventBtn", backFromEventBtn],
    ["eventSection", eventSection],
    ["otherWrap", otherWrap],
    ["otherEventInput", otherEventInput],
    ["genLooksBtn", genLooksBtn],
    ["outfitsGrid", outfitsGrid],
    ["outfitsSub", outfitsSub],
    ["backToEventBtn", backToEventBtn],
  ].forEach(([name, el]) => {
    if (!el) missing.push(name);
  });

  if (missing.length) {
    console.error("upload.js: missing DOM elements:", missing);
    return;
  }

  // ===== Helpers =====
  const setNote = (text, ok) => {
    note.textContent = text || "";
    note.className = ok === true ? "note ok" : ok === false ? "note err" : "note";
  };

  const setLoadingText = (text) => {
    const el = document.querySelector("#loadingStage .loadingText b");
    if (el) el.textContent = text || "";
  };

  const escapeHtml = (s) =>
    String(s).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[c]);

  const hasFile = (input) => Boolean(input?.files && input.files.length > 0);

  const setChip = (chipEl, ok) => {
    chipEl.classList.toggle("ok", ok);
    chipEl.textContent = ok ? "Загружено" : "Не загружено";
  };

  const canSubmitReal = () =>
    email.checkValidity() && hasFile(faceInput) && hasFile(fullInput);

  const showStage = (name) => {
    uploadStage.style.display = name === "upload" ? "block" : "none";
    loadingStage.style.display = name === "loading" ? "block" : "none";
    resultStage.style.display = name === "result" ? "block" : "none";
    outfitsStage.style.display = name === "outfits" ? "block" : "none";
    uploadLegal.style.display = name === "upload" ? "" : "none";
  };

  const showStageKeepScroll = (name) => {
    const y = window.scrollY;
    showStage(name);
    requestAnimationFrame(() => window.scrollTo({ top: y }));
  };

  const scheduleUpdate = () => {
    requestAnimationFrame(() => setTimeout(update, 0));
  };

  const update = () => {
    setChip(faceChip, hasFile(faceInput));
    setChip(fullChip, hasFile(fullInput));
    sendBtn.disabled = MOCK ? !email.value.trim() : !canSubmitReal();
  };

  const resetOnClick = (input) => {
    input.addEventListener(
      "click",
      () => {
        input.value = "";
      },
      { passive: true }
    );
  };

  const fetchJson = async (url, opts = {}) => {
    const timeout = isMobile ? 1200000 : 6000000;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);

    try {
      const res = await fetch(url, { ...opts, signal: controller.signal });
      clearTimeout(timeoutId);

      const raw = await res.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        throw new Error("Сервер вернул не JSON: " + raw.slice(0, 160));
      }

      if (!res.ok) {
        const isFileSizeError =
          data?.error?.includes?.("слишком большой") ||
          data?.error?.includes?.("макс 4") ||
          data?.error?.includes?.("File too");

        if (isFileSizeError) {
          console.warn("File size error detected:", data.error);
          throw new Error("FILE_SIZE_ERROR");
        }

        throw new Error(data?.error || `HTTP ${res.status}`);
      }

      return data;
    } catch (err) {
      clearTimeout(timeoutId);
      if (err.message === "FILE_SIZE_ERROR") throw err;
      if (err.name === "AbortError") {
        throw new Error(`Таймаут запроса (${timeout / 1000} секунд)`);
      }
      throw err;
    }
  };

  // ===== Visible errors on Event screen =====
  const showEventError = (msg) => {
    const text = String(msg || "").trim();
    if (!text) return;

    let box = document.getElementById("eventErrorBox");
    if (!box) {
      box = document.createElement("div");
      box.id = "eventErrorBox";
      box.style.cssText =
        "margin:12px 0;padding:10px 12px;border-radius:12px;" +
        "border:1px solid rgba(220,0,0,.35);background:rgba(220,0,0,.08);" +
        "color:#b00000;font-size:14px;line-height:1.35;";
      eventSection.prepend(box);
    }
    box.textContent = text;
  };

  const clearEventError = () => {
    const box = document.getElementById("eventErrorBox");
    if (box) box.remove();
  };

  // ===== Event mode state =====
  let archetypeData = null;
  let selectedEvent = "";

  const clearEventSelection = () => {
    selectedEvent = "";
    genLooksBtn.disabled = true;
    otherEventInput.value = "";
    otherWrap.style.display = "none";
    Array.from(eventSection.querySelectorAll(".eventBtn")).forEach((b) =>
      b.classList.remove("active")
    );
  };

  const setEventMode = (on) => {
    if (on) clearEventError();

    eventSection.style.display = on ? "block" : "none";
    toEventLabel.style.display = on ? "none" : "inline";
    toEventBtn.style.display = on ? "none" : "inline-flex";
    backFromEventBtn.style.display = on ? "inline-flex" : "none";
    aiBullets.style.display = on ? "none" : "";
    if (!on) clearEventSelection();
  };

  const getEventText = () => {
    if (!selectedEvent) return "";
    if (selectedEvent !== "другое") return selectedEvent;
    const v = otherEventInput.value.trim();
    return v.length >= 2 ? v : "";
  };

  const renderResult = ({ type, reason, bullets }) => {
    archetypeData = { type, reason, bullets };

    aiTitle.textContent = `Твой типаж — ${type || "—"}`;
    aiReason.textContent = reason || "";

    aiBullets.innerHTML = "";
    (Array.isArray(bullets) ? bullets : []).slice(0, 8).forEach((b) => {
      const span = document.createElement("span");
      span.className = "achip";
      span.innerHTML = `<span>${escapeHtml(String(b))}</span>`;
      aiBullets.appendChild(span);
    });

    setEventMode(false);
    showStageKeepScroll("result");
  };

  const renderOutfits = ({ eventText, images }) => {
    outfitsSub.textContent = `Мероприятие: ${eventText}`;
    outfitsGrid.innerHTML = "";

    (Array.isArray(images) ? images : []).slice(0, OUTFIT_COUNT).forEach((src) => {
      const card = document.createElement("div");
      card.className = "outfitCard";

      const img = document.createElement("img");
      img.loading = "lazy";
      img.alt = "Сгенерированный образ";

      const proxyUrl = `${WORKER_BASE}/regru_fetch.php?url=${encodeURIComponent(src)}`;
      img.src = proxyUrl;

      img.onerror = () => {
        img.src = src;
      };

      img.dataset.original = src;

      card.appendChild(img);
      outfitsGrid.appendChild(card);
    });

    showStageKeepScroll("outfits");
  };

  // ===== Image processing =====
  const MB = (bytes) => bytes / 1024 / 1024;

  const isHeicLike = (file) => {
    const name = String(file?.name || "").toLowerCase();
    const type = String(file?.type || "").toLowerCase();
    return (
      type.includes("heic") ||
      type.includes("heif") ||
      name.endsWith(".heic") ||
      name.endsWith(".heif")
    );
  };

  const convertHeicToJpegIfNeeded = async (file) => {
    if (!isHeicLike(file)) return file;

    if (typeof window.heic2any !== "function") {
      throw new Error(
        "Фото HEIC/HEIF (iPhone). Подключи heic2any (CDN) или включи на iPhone: Settings → Camera → Formats → Most Compatible."
      );
    }

    const outBlob = await window.heic2any({
      blob: file,
      toType: "image/jpeg",
      quality: 0.9,
    });

    const blob = Array.isArray(outBlob) ? outBlob[0] : outBlob;
    return new File([blob], file.name.replace(/\.(heic|heif)$/i, ".jpg"), {
      type: "image/jpeg",
      lastModified: Date.now(),
    });
  };

  const loadImageFromFile = async (file) => {
    const url = URL.createObjectURL(file);
    try {
      return await new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = url;
      });
    } finally {
      URL.revokeObjectURL(url);
    }
  };

  const canvasToBlob = async (canvas, mimeType, quality) =>
    await new Promise((resolve) => canvas.toBlob(resolve, mimeType, quality));

  const drawSquareCrop = (ctx, img, size, alpha) => {
    const sourceSize = Math.min(img.width, img.height);
    const sx = (img.width - sourceSize) / 2;
    const sy = (img.height - sourceSize) / 2;

    if (alpha) {
      ctx.clearRect(0, 0, size, size);
    } else {
      ctx.fillStyle = "#fff";
      ctx.fillRect(0, 0, size, size);
    }

    ctx.drawImage(img, sx, sy, sourceSize, sourceSize, 0, 0, size, size);
  };

  /**
   * Squares + crops and outputs PNG/JPEG.
   * For PNG: shrinks pixels until <= maxSizeMB.
   * For JPEG: reduces quality, then shrinks pixels if needed.
   */
  async function toSquareFile(file, opts) {
    const {
      mimeType,
      fileName,
      maxSizeMB,
      targetSize,
      minSize,
      jpegQualityStart,
      jpegQualityMin,
    } = opts;

    const normalized = await convertHeicToJpegIfNeeded(file);
    const img = await loadImageFromFile(normalized);

    let size = Math.min(targetSize, Math.min(img.width, img.height));
    size = Math.max(minSize, size);

    const alpha = mimeType === "image/png";
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d", { alpha });

    if (mimeType === "image/png") {
      while (true) {
        canvas.width = size;
        canvas.height = size;

        drawSquareCrop(ctx, img, size, true);

        const blob = await canvasToBlob(canvas, "image/png");
        if (!blob) throw new Error("Не удалось подготовить PNG (canvas.toBlob вернул null)");

        if (MB(blob.size) <= maxSizeMB || size <= minSize) {
          return new File([blob], fileName, {
            type: "image/png",
            lastModified: Date.now(),
          });
        }

        size = Math.max(minSize, Math.floor(size * 0.85));
      }
    }

    let q = jpegQualityStart;
    while (true) {
      canvas.width = size;
      canvas.height = size;

      drawSquareCrop(ctx, img, size, false);

      const blob = await canvasToBlob(canvas, "image/jpeg", q);
      if (!blob) throw new Error("Не удалось подготовить JPEG (canvas.toBlob вернул null)");

      if (MB(blob.size) <= maxSizeMB) {
        return new File([blob], fileName, {
          type: "image/jpeg",
          lastModified: Date.now(),
        });
      }

      if (q > jpegQualityMin) {
        q = Math.max(jpegQualityMin, q - 0.08);
      } else {
        size = Math.max(minSize, Math.floor(size * 0.85));
        if (size === minSize) {
          return new File([blob], fileName, {
            type: "image/jpeg",
            lastModified: Date.now(),
          });
        }
      }
    }
  }

  // Outfits: backend requires full = PNG
  const toFullPngForOutfits = async (file) =>
    await toSquareFile(file, {
      mimeType: "image/png",
      fileName: "full.png",
      maxSizeMB: 3.8,
      targetSize: isMobile ? 512 : OUTFIT_SIZE,
      minSize: 384,
    });

  // Outfits: safest to keep face PNG too
  const toFacePngForOutfits = async (file) =>
    await toSquareFile(file, {
      mimeType: "image/png",
      fileName: "face.png",
      maxSizeMB: 3.2,
      targetSize: isMobile ? 512 : OUTFIT_SIZE,
      minSize: 384,
    });

  // Submit archetype: JPEG smaller and usually accepted
  const toFaceJpegForSubmit = async (file) =>
    await toSquareFile(file, {
      mimeType: "image/jpeg",
      fileName: "face.jpg",
      maxSizeMB: 3.6,
      targetSize: 2048,
      minSize: 512,
      jpegQualityStart: isMobile ? 0.85 : 0.9,
      jpegQualityMin: 0.5,
    });

  const toFullJpegForSubmit = async (file) =>
    await toSquareFile(file, {
      mimeType: "image/jpeg",
      fileName: "full.jpg",
      maxSizeMB: 3.6,
      targetSize: 2048,
      minSize: 512,
      jpegQualityStart: isMobile ? 0.85 : 0.9,
      jpegQualityMin: 0.5,
    });

  // ===== Polling control =====
  let outfitsPollTimer = null;
  const stopOutfitsPolling = () => {
    if (outfitsPollTimer) {
      clearInterval(outfitsPollTimer);
      outfitsPollTimer = null;
      console.log("Polling stopped");
    }
  };

  // ===== Upload inputs =====
  resetOnClick(faceInput);
  resetOnClick(fullInput);

  faceInput.addEventListener("change", scheduleUpdate);
  fullInput.addEventListener("change", scheduleUpdate);
  faceInput.addEventListener("input", scheduleUpdate);
  fullInput.addEventListener("input", scheduleUpdate);
  email.addEventListener("input", scheduleUpdate);

  // ===== Buttons =====
  aiReset.addEventListener("click", () => {
    stopOutfitsPolling();
    location.reload();
  });

  toEventBtn.addEventListener("click", () => setEventMode(true));
  backFromEventBtn.addEventListener("click", () => setEventMode(false));

  backToEventBtn.addEventListener("click", () => {
    stopOutfitsPolling();
    showStageKeepScroll("result");
    setEventMode(true);
  });

  // ===== Pick event =====
  eventSection.addEventListener("click", (e) => {
    const btn = e.target?.closest?.(".eventBtn");
    if (!btn) return;

    Array.from(eventSection.querySelectorAll(".eventBtn")).forEach((b) =>
      b.classList.remove("active")
    );
    btn.classList.add("active");

    selectedEvent = btn.getAttribute("data-event") || "";

    if (selectedEvent === "другое") {
      otherWrap.style.display = "block";
      otherEventInput.focus();
    } else {
      otherWrap.style.display = "none";
      otherEventInput.value = "";
    }

    genLooksBtn.disabled = !getEventText();
  });

  otherEventInput.addEventListener("input", () => {
    genLooksBtn.disabled = !getEventText();
  });

  // ===== Generate outfits =====
  genLooksBtn.addEventListener("click", async () => {
    const eventText = getEventText();
    if (!eventText) return;

    if (!archetypeData?.type) {
      setNote("Сначала нужен типаж.", false);
      showEventError("Сначала нужен типаж.");
      return;
    }

    stopOutfitsPolling();
    clearEventError();
    setNote("", null);
    showStageKeepScroll("loading");

    if (MOCK) {
      setLoadingText("Генерируем образ…");
      await new Promise((r) => setTimeout(r, 900));
      renderOutfits({ eventText, images: ["/uploads/outfits/look1.png"] });
      return;
    }

    try {
      if (!hasFile(fullInput)) throw new Error("Нужно фото в полный рост");

      setLoadingText("Подготовка фото…");

      const fullSq = await toFullPngForOutfits(fullInput.files[0]);
      const faceSq = await toFacePngForOutfits(faceInput.files[0]);

      console.log("Outfits files:", {
        full: { name: fullSq.name, type: fullSq.type, mb: MB(fullSq.size).toFixed(2) },
        face: { name: faceSq.name, type: faceSq.type, mb: MB(faceSq.size).toFixed(2) },
      });

      setLoadingText("Отправляем…");

      const fd = new FormData();
      fd.append("email", email.value || "");
      fd.append("event", eventText);
      fd.append("archetype", JSON.stringify(archetypeData));
      fd.append("full", fullSq, fullSq.name);
      fd.append("face", faceSq, faceSq.name);

      const start = await fetchJson(`${WORKER_BASE}/worker_outfits_start.php`, {
        method: "POST",
        body: fd,
      });

      if (!start?.ok || !start?.job) {
        throw new Error(start?.error || "Не удалось создать задачу");
      }

      const job = start.job;

      const startedAt = Date.now();
      const maxMs = isMobile ? 5 * 60 * 1000 : 4 * 60 * 1000;

      const pollOnce = async () => {
        if (Date.now() - startedAt > maxMs) {
          stopOutfitsPolling();
          showStageKeepScroll("result");
          setEventMode(true);
          const m = "Генерация заняла слишком много времени. Попробуй ещё раз.";
          setNote(m, false);
          showEventError(m);
          return;
        }

        let st;
        try {
          const url = `${WORKER_BASE}/worker_outfits_status.php?job=${encodeURIComponent(job)}`;
          st = await fetchJson(url);
        } catch {
          setLoadingText("В очереди…");
          return;
        }

        const status = String(st?.status || "").toLowerCase().trim();

        if (status === "queued") return void setLoadingText("В очереди…");
        if (status === "running") return void setLoadingText("Генерируем образ…");
        if (status === "saving") return void setLoadingText("Сохраняем…");

        if (status === "error" || st?.ok === false || st?.error) {
          stopOutfitsPolling();
          showStageKeepScroll("result");
          setEventMode(true);
          const m = st?.error || "Генерация не удалась. Попробуй другое фото.";
          setNote(m, false);
          showEventError(m);
          return;
        }

        if (status === "done") {
          stopOutfitsPolling();

          const images = Array.isArray(st?.images) ? st.images : [];
          if (images.length === 0) {
            showStageKeepScroll("result");
            setEventMode(true);
            const m = "Ошибка: сервер не вернул картинку.";
            setNote(m, false);
            showEventError(m);
            return;
          }

          setLoadingText("Готово!");
          setTimeout(() => {
            renderOutfits({
              eventText,
              images: images.slice(0, OUTFIT_COUNT),
            });
          }, 100);
          return;
        }

        setLoadingText("Обрабатываем…");
      };

      await pollOnce();
      const pollInterval = isMobile ? 2500 : 1500;
      outfitsPollTimer = setInterval(pollOnce, pollInterval);
    } catch (err) {
      console.error("Generation error:", err);
      stopOutfitsPolling();
      showStageKeepScroll("result");
      setEventMode(true);

      const msg = String(err?.message || err);

      let userMsg = msg;
      if (msg === "FILE_SIZE_ERROR") userMsg = "Фото слишком большое. Выбери фото меньшего размера.";
      if (msg.includes("full должен быть PNG")) userMsg = "Сервер требует full.png. Попробуй другое фото.";
      if (msg.includes("canvas.toBlob вернул null")) {
        userMsg =
          "Не удалось подготовить картинку на телефоне (память/формат). Попробуй другое фото или сделай скриншот фото (обычно меньше).";
      }

      setNote(`Ошибка: ${userMsg}`, false);
      showEventError(`Ошибка: ${userMsg}`);
    }
  });

  window.addEventListener("beforeunload", stopOutfitsPolling);

  // ===== Initial =====
  showStage("upload");
  update();
  if (FORCE_LOADING) showStageKeepScroll("loading");

  // ===== Submit archetype =====
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (MOCK) {
      if (!email.value.trim()) {
        setNote("Укажи email (для теста).", false);
        return;
      }

      sendBtn.disabled = true;
      setNote("", null);
      showStageKeepScroll("loading");
      setLoadingText("Анализируем фото…");

      await new Promise((r) => setTimeout(r, 900));

      renderResult({
        type: "Муза",
        reason: "Мок-результат для локального теста интерфейса.",
        bullets: ["Пункт 1", "Пункт 2", "Пункт 3", "Пункт 4"],
      });

      sendBtn.disabled = false;
      return;
    }

    if (!canSubmitReal()) return;

    sendBtn.disabled = true;
    setNote("", null);
    showStageKeepScroll("loading");
    setLoadingText("Проверяем фото…");

    try {
      const faceFile = faceInput?.files?.[0] || null;
      const fullFile = fullInput?.files?.[0] || null;

      if (!faceFile || !fullFile) {
        throw new Error("Нужно загрузить 2 фото: лицо и полный рост");
      }

      setLoadingText("Оптимизируем фото…");

      const [faceReady, fullReady] = await Promise.all([
        toFaceJpegForSubmit(faceFile),
        toFullJpegForSubmit(fullFile),
      ]);

      setLoadingText("Анализируем фото…");

      const fd = new FormData();
      fd.append("email", String(email.value || "").trim());
      fd.append("face", faceReady, faceReady.name || "face.jpg");
      fd.append("full", fullReady, fullReady.name || "full.jpg");

      let data;
      try {
        data = await fetchJson(`${WORKER_BASE}/worker_submit.php`, { method: "POST", body: fd });
      } catch (err) {
        if (err.message === "FILE_SIZE_ERROR") {
          setLoadingText("Оптимизируем фото…");

          const [face2, full2] = await Promise.all([
            toSquareFile(faceFile, {
              mimeType: "image/jpeg",
              fileName: "face.jpg",
              maxSizeMB: 2.0,
              targetSize: 1400,
              minSize: 512,
              jpegQualityStart: 0.82,
              jpegQualityMin: 0.5,
            }),
            toSquareFile(fullFile, {
              mimeType: "image/jpeg",
              fileName: "full.jpg",
              maxSizeMB: 2.0,
              targetSize: 1400,
              minSize: 512,
              jpegQualityStart: 0.82,
              jpegQualityMin: 0.5,
            }),
          ]);

          const retryFd = new FormData();
          retryFd.append("email", String(email.value || "").trim());
          retryFd.append("face", face2, face2.name || "face.jpg");
          retryFd.append("full", full2, full2.name || "full.jpg");

          data = await fetchJson(`${WORKER_BASE}/worker_submit.php`, {
            method: "POST",
            body: retryFd,
          });
        } else {
          throw err;
        }
      }

      if (!data?.ok) throw new Error(data?.error || "Ошибка сервера");

      const r = data.result;
      if (!r || !r.type || !r.reason) throw new Error("AI вернул пустой результат");

      renderResult({
        type: String(r.type).trim(),
        reason: String(r.reason).trim(),
        bullets: Array.isArray(r.bullets) ? r.bullets : [],
      });

      setNote("", null);
    } catch (err) {
      const msg = String(err?.message || err);

      showStageKeepScroll("upload");

      if (msg.includes("HEIC/HEIF")) {
        setNote(msg, false);
      } else if (msg.includes("слишком большой") || msg.includes("макс 4") || msg === "FILE_SIZE_ERROR") {
        setNote("Пожалуйста, выберите фото меньшего размера", false);
      } else {
        setNote(`Ошибка: ${msg}`, false);
      }
    } finally {
      update();
    }
  });
});
