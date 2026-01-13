const { createClient } = require("@supabase/supabase-js");

function isValidEmail(email) {
  return typeof email === "string" && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

exports.handler = async (event) => {
  if (event.httpMethod !== "POST") {
    return { statusCode: 405, body: "Method Not Allowed" };
  }

  try {
    const { email } = JSON.parse(event.body || "{}");

    if (!isValidEmail(email)) {
      return {
        statusCode: 400,
        body: JSON.stringify({ ok: false, error: "Некорректный email" })
      };
    }

    const supabaseUrl = process.env.SUPABASE_URL;
    const serviceKey = process.env.SUPABASE_SERVICE_ROLE_KEY;

    if (!supabaseUrl || !serviceKey) {
      return {
        statusCode: 500,
        body: JSON.stringify({ ok: false, error: "Нет переменных окружения Supabase" })
      };
    }

    const supabase = createClient(supabaseUrl, serviceKey);

    // чтобы повторный email не ломал всё — upsert по уникальному email
    const { error } = await supabase
      .from("waitlist_emails")
      .upsert({ email: email.toLowerCase() }, { onConflict: "email" });

    if (error) {
      return { statusCode: 500, body: JSON.stringify({ ok: false, error: error.message }) };
    }

    return { statusCode: 200, body: JSON.stringify({ ok: true }) };
  } catch (e) {
    return { statusCode: 500, body: JSON.stringify({ ok: false, error: "Ошибка сервера" }) };
  }
};

