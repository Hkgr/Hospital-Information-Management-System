export type User = {
  id: number;
  staff_id: number | null;
  username: string;
  name: string;
  email: string | null;
  must_change_password: boolean;
  last_login_at: string | null;
};

export type Identity = {
  user: User;
  access: {
    facility: { id: number; code: string; name_ar: string; timezone: string };
    roles: { code: string; name_ar: string; name_en: string | null }[];
    permissions: string[];
  }[];
};

const TOKEN_KEY = "hospital.bearer";
let memoryToken: string | null = null;

// No existing frontend session mechanism: retain a Bearer token in this tab
// only, never localStorage, a URL, logs, or a role-derived cookie.
export function getToken(): string | null {
  try { return sessionStorage.getItem(TOKEN_KEY) || memoryToken; }
  catch { return memoryToken; }
}

function saveToken(token: string | null) {
  memoryToken = token;
  try {
    if (token) sessionStorage.setItem(TOKEN_KEY, token);
    else sessionStorage.removeItem(TOKEN_KEY);
  } catch { /* Storage-disabled browsers retain only the in-memory token. */ }
  if (typeof window !== "undefined") window.dispatchEvent(new Event("hospital-session-change"));
}

export function subscribeSession(listener: () => void) {
  for (const event of ["hospital-session-change", "storage", "focus"]) window.addEventListener(event, listener);
  return () => { for (const event of ["hospital-session-change", "storage", "focus"]) window.removeEventListener(event, listener); };
}

export class AuthError extends Error {
  constructor(public status: number, public code: string, message: string,
    public fields: Partial<Record<string, string>> = {}) {
    super(message);
  }
}

export async function apiRequest<T>(endpoint: string, options: RequestInit = {}, mode: "data" | "envelope" | "blob" = "data"): Promise<T> {
  const token = getToken();
  let response: Response;
  try {
    response = await fetch(`/hospital-api/${endpoint}`, {
      ...options, cache: "no-store", credentials: "omit", signal: options.signal ? AbortSignal.any([options.signal, AbortSignal.timeout(mode === "blob" ? 60000 : 15000)]) : AbortSignal.timeout(mode === "blob" ? 60000 : 15000),
      headers: {
        Accept: "application/json", "Content-Type": "application/json",
        ...(token && endpoint !== "login" ? { Authorization: `Bearer ${token}` } : {}),
      },
    });
  } catch {
    if (options.signal?.aborted) throw new DOMException("Request cancelled", "AbortError");
    throw new AuthError(0, "NETWORK_ERROR", "تعذّر الاتصال بالخادم. تحقق من اتصالك ثم حاول مجددًا.");
  }
  if (getToken() !== token) throw new AuthError(0, "STALE_SESSION", "تغيرت جلسة المستخدم.");
  if (response.status === 204) return undefined as T;
  if (response.ok && mode === "blob") {
    const blob = await response.blob();
    if (getToken() !== token || options.signal?.aborted) throw new DOMException("Request cancelled", "AbortError");
    return { blob, filename: response.headers.get("Content-Disposition")?.match(/filename="([A-Za-z0-9.-]+)"/)?.[1] } as T;
  }
  const body = await response.json().catch(() => null);
  if (getToken() !== token) throw new AuthError(0, "STALE_SESSION", "تغيرت جلسة المستخدم.");
  if (!response.ok) {
    const code = body?.error?.code || "REQUEST_FAILED";
    if (endpoint !== "login" && (response.status === 401 || code === "ACCOUNT_INACTIVE")) saveToken(null);
    const fields: AuthError["fields"] = {};
    if (response.status === 422) {
      for (const [key, errors] of Object.entries(body?.errors ?? {})) {
        if (Array.isArray(errors) && typeof errors[0] === "string") fields[key] = errors[0];
      }
      if (body?.errors?.username) fields.username = "أدخل اسم مستخدم صحيحًا لا يتجاوز 60 محرفًا.";
      if (body?.errors?.password) fields.password = "أدخل كلمة المرور.";
    }
    const clinicMessages: Record<string, string> = {
      CLINIC_VERSION_CONFLICT: "عدّل مستخدم آخر هذه العيادة. أغلق النافذة وأعد تحميل البيانات قبل التعديل.",
      CLINIC_PERIOD_CONFLICT: "يوجد ارتباط مجدول أو فترات متداخلة لهذا الطبيب. راجع السجل قبل التعديل.",
      CLINIC_REFERENCED: "لا يمكن حذف عيادة مرتبطة بسجلات. يمكنك تعطيلها بإجراء منفصل.",
      CLINIC_NOT_FOUND: "العيادة غير موجودة في المنشأة المحددة.",
      EXPORT_LIMIT_EXCEEDED: "نتائج التقرير أكبر من الحد الآمن. ضيّق نطاق الفلاتر ثم أعد المحاولة.",
      PDF_LAYOUT_LIMIT_EXCEEDED: "النص في إحدى الخلايا طويل جدًا لتقرير القائمة. أخفِ عمود التوصيف أو الأطباء، أو استخدم Excel أو تقرير العيادة المفردة.",
    };
    const message = clinicMessages[code] ?? (response.status === 429 ? "تجاوزت عدد محاولات الدخول. انتظر دقيقة ثم حاول مجددًا."
      : code === "ACCOUNT_INACTIVE" ? "هذا الحساب غير فعال. راجع مسؤول النظام."
      : response.status === 401 ? (endpoint === "login" ? "اسم المستخدم أو كلمة المرور غير صحيحة." : "انتهت صلاحية الدخول. سجّل الدخول مجددًا.")
      : response.status === 403 ? "لا يملك هذا الدخول صلاحية الوصول المطلوبة. راجع مسؤول النظام."
      : response.status === 422 ? "تحقق من بيانات الحقول ثم حاول مجددًا."
      : "تعذّر إتمام الطلب الآن. حاول مجددًا بعد قليل.");
    throw new AuthError(response.status, code, message, fields);
  }
  if (!body?.data) throw new AuthError(502, "INVALID_RESPONSE", "تعذّر قراءة استجابة الخادم. حاول مجددًا.");
  return (mode === "envelope" ? body : body.data) as T;
}

export async function login(username: string, password: string) {
  const data = await apiRequest<Identity & { token: string; token_type: string }>("login", {
    method: "POST", body: JSON.stringify({ username: username.trim(), password, device_name: "hospital-web" }),
  });
  if (!data.token || data.token_type !== "Bearer" || !data.user?.id || !Array.isArray(data.access)) {
    throw new AuthError(502, "INVALID_RESPONSE", "تعذّر قراءة استجابة الدخول. حاول مجددًا.");
  }
  saveToken(data.token);
  return data;
}

export const currentUser = (signal?: AbortSignal) => apiRequest<Identity>("user", { signal });
export async function logout() {
  await apiRequest<void>("logout", { method: "POST" });
  saveToken(null);
}
