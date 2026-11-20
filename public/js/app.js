const { createApp, computed, ref, reactive, onMounted, watch } = Vue;
const { createRouter, createWebHistory, useRoute } = VueRouter;

const API = '/api';
const EXCHANGES = ['Binance', 'Bybit', 'BingX'];

// Original fetch (без interceptor'а), используется из checkAuth/login/logout
const originalFetch = window.fetch.bind(window);

// Глобальное состояние авторизации — обновляется через me.php и после login/logout
const authState = reactive({ required: false, authed: false, checked: false });

async function checkAuth() {
  try {
    const r = await originalFetch(`${API}/me.php`);
    const j = await r.json();
    authState.required = !!j.auth_required;
    authState.authed = !!j.authenticated;
  } catch {
    authState.required = false;
    authState.authed = true;
  }
  authState.checked = true;
}

// Fetch interceptor: 401 на любом API → сбрасываем auth и перебрасываем на /login
window.fetch = async (...args) => {
  const r = await originalFetch(...args);
  if (r.status === 401) {
    const url = typeof args[0] === 'string' ? args[0] : args[0].url;
    if (!url.endsWith('/login.php')) {
      authState.authed = false;
      if (window.appRouter && window.appRouter.currentRoute.value.name !== 'login') {
        window.appRouter.push('/login');
      }
      throw new Error('Unauthorized');
    }
  }
  return r;
};

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/deals' },
    { path: '/login', name: 'login' },
    { path: '/deals', name: 'deals' },
    { path: '/analytics', name: 'analytics' },
    { path: '/calculator', name: 'calculator' },
    { path: '/:pathMatch(.*)*', redirect: '/deals' },
  ],
});
window.appRouter = router;

router.beforeEach(async (to) => {
  if (!authState.checked) await checkAuth();
  if (authState.required && !authState.authed && to.name !== 'login') {
    return { name: 'login' };
  }
  if (authState.authed && to.name === 'login') {
    return { name: 'deals' };
  }
});

function todayISO() {
  return new Date().toISOString().slice(0, 10);
}
function currentMonth() {
  return new Date().toISOString().slice(0, 7);
}
function emptyForm() {
  return {
    id: null,
    deal_date: todayISO(),
    direction: 'UAH_USDT',
    exchange: 'Binance',
    amount_from: null,
    amount_to: null,
    declared_rate: null,
    counterparty: '',
    note: '',
  };
}

const app = createApp({
  setup() {
    const route = useRoute();

    const selectedMonth = ref(currentMonth());

    const deals = ref([]);
    const loadingDeals = ref(false);

    const analytics = ref(null);
    const loadingAnalytics = ref(false);

    const modalOpen = ref(false);
    const form = reactive(emptyForm());
    const formError = ref('');
    const saving = ref(false);

    // Login state
    const loginPassword = ref('');
    const loginPasswordVisible = ref(false);
    const loginError = ref('');
    const loginSubmitting = ref(false);

    const analyticsPeriod = reactive({
      mode: 'month',       // 'month' | 'salary' | 'range'
      anchorMonth: currentMonth(),  // якорь для month и salary режимов
      from: '',
      to: '',
    });

    const calc = reactive({
      uah: null,
      buyRate: null,
      sellRate: null,
      nbuUsdUah: null,
      nbuThbUah: null,
      lastBuyRate: null,
      lastSellRate: null,
      loading: false,
    });

    const exchanges = EXCHANGES;

    const isLoginRoute = computed(() => route.name === 'login');
    const authRequired = computed(() => authState.required);

    const monthLabel = computed(() => {
      const [y, m] = selectedMonth.value.split('-');
      const dt = new Date(+y, +m - 1, 1);
      return dt.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
    });

    const analyticsPeriodLabel = computed(() => {
      if (!analyticsPeriod.from || !analyticsPeriod.to) return '';
      if (analyticsPeriod.mode === 'month') {
        const [y, m] = analyticsPeriod.anchorMonth.split('-');
        const dt = new Date(+y, +m - 1, 1);
        return dt.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
      }
      return `${formatDate(analyticsPeriod.from)} — ${formatDate(analyticsPeriod.to)}`;
    });

    const monthDeals = computed(() => deals.value);

    const buyCount = computed(() =>
      monthDeals.value.filter(d => d.direction === 'UAH_USDT').length
    );
    const sellCount = computed(() =>
      monthDeals.value.filter(d => d.direction === 'USDT_THB').length
    );

    const computedRate = computed(() => {
      if (!form.amount_from || !form.amount_to) return null;
      return form.direction === 'UAH_USDT'
        ? form.amount_from / form.amount_to
        : form.amount_to / form.amount_from;
    });

    const rateUnit = computed(() =>
      form.direction === 'UAH_USDT' ? 'UAH за 1 USDT' :
      form.direction === 'USDT_THB' ? 'THB за 1 USDT' : ''
    );

    const rateDiffPct = computed(() => {
      if (!form.declared_rate || !computedRate.value) return null;
      return (computedRate.value - form.declared_rate) / form.declared_rate * 100;
    });

    const rateDiffAbs = computed(() => {
      if (!form.declared_rate || !computedRate.value) return null;
      return computedRate.value - form.declared_rate;
    });

    const buyRateDiffPct = computed(() => {
      const mine = analytics.value?.average_rates?.buy_uah_per_usdt;
      const nbu = analytics.value?.average_rates?.nbu_avg_buy_usd_uah;
      if (!mine || !nbu) return null;
      return (mine - nbu) / nbu * 100;
    });

    const sellRateDiffPct = computed(() => {
      const mine = analytics.value?.average_rates?.sell_thb_per_usdt;
      const nbu = analytics.value?.average_rates?.nbu_avg_sell_usd_thb;
      if (!mine || !nbu) return null;
      return (mine - nbu) / nbu * 100;
    });

    const calcReady = computed(() =>
      calc.uah > 0 && calc.buyRate > 0 && calc.sellRate > 0
    );
    const calcUsdt = computed(() => calcReady.value ? calc.uah / calc.buyRate : null);
    const calcThb = computed(() => calcReady.value ? calcUsdt.value * calc.sellRate : null);
    const calcEffectiveUahPerThb = computed(() =>
      calcReady.value && calcThb.value > 0 ? calc.uah / calcThb.value : null
    );
    const calcNbuCross = computed(() =>
      calc.nbuUsdUah && calc.nbuThbUah ? calc.nbuUsdUah / calc.nbuThbUah : null
    );
    const calcAlternativeUah = computed(() =>
      calcReady.value && calc.nbuThbUah ? calcThb.value * calc.nbuThbUah : null
    );
    const calcSavings = computed(() =>
      calcAlternativeUah.value !== null ? calcAlternativeUah.value - calc.uah : null
    );
    const calcSavingsPct = computed(() =>
      calcSavings.value !== null && calcAlternativeUah.value > 0
        ? calcSavings.value / calcAlternativeUah.value * 100 : null
    );
    const calcBreakEvenSellRate = computed(() =>
      calc.buyRate && calc.nbuThbUah ? calc.buyRate / calc.nbuThbUah : null
    );

    // --- helpers ---
    function fromCurrency(dir) { return dir === 'UAH_USDT' ? 'UAH' : dir === 'USDT_THB' ? 'USDT' : ''; }
    function toCurrency(dir) { return dir === 'UAH_USDT' ? 'USDT' : dir === 'USDT_THB' ? 'THB' : ''; }
    function directionLabel(dir) { return dir === 'UAH_USDT' ? 'UAH → USDT' : 'USDT → THB'; }
    function formatDate(iso) {
      const [y, m, d] = iso.split('-');
      return `${d}.${m}.${y.slice(2)}`;
    }
    function formatAmount(n) {
      if (n === null || n === undefined || n === '') return '—';
      const num = Number(n);
      if (!isFinite(num)) return '—';
      return num.toLocaleString('uk-UA', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }
    function formatRate(n) {
      if (n === null || n === undefined || n === '') return '';
      const num = Number(n);
      if (!isFinite(num) || num === 0) return '';
      return num.toLocaleString('en-US', {
        minimumFractionDigits: num < 1 ? 4 : 2,
        maximumFractionDigits: num < 1 ? 6 : 4,
      });
    }

    function nbuForDeal(d) {
      const usd = d.nbu_usd_uah ? Number(d.nbu_usd_uah) : null;
      const thb = d.nbu_thb_uah ? Number(d.nbu_thb_uah) : null;
      if (d.direction === 'UAH_USDT') {
        return usd ? formatRate(usd) : '—';
      }
      if (!usd || !thb) return '—';
      return formatRate(usd / thb);
    }
    function nbuTooltip(d) {
      const usd = d.nbu_usd_uah ? Number(d.nbu_usd_uah) : null;
      const thb = d.nbu_thb_uah ? Number(d.nbu_thb_uah) : null;
      if (d.direction === 'UAH_USDT') return `НБУ USD/UAH на ${d.deal_date}`;
      const parts = [`НБУ USD/THB (кросс через UAH) на ${d.deal_date}`];
      if (usd) parts.push(`USD/UAH: ${formatRate(usd)}`);
      if (thb) parts.push(`THB/UAH: ${formatRate(thb)}`);
      return parts.join('\n');
    }
    function rateTooltip(d) {
      const unit = d.direction === 'UAH_USDT' ? 'UAH/USDT' : 'THB/USDT';
      const actual = `Фактический: ${formatRate(d.rate)} ${unit}`;
      if (d.declared_rate === null || d.declared_rate === undefined) return actual;
      const declared = Number(d.declared_rate);
      const rate = Number(d.rate);
      const diffPct = (rate - declared) / declared * 100;
      const sign = diffPct >= 0 ? '+' : '';
      return `Заявленный: ${formatRate(declared)} ${unit}\n${actual}\nОтклонение: ${sign}${diffPct.toFixed(3)}%`;
    }

    // --- data loading ---
    async function loadDeals() {
      loadingDeals.value = true;
      try {
        const [y, m] = selectedMonth.value.split('-');
        const from = `${y}-${m}-01`;
        const lastDay = new Date(+y, +m, 0).getDate();
        const to = `${y}-${m}-${String(lastDay).padStart(2, '0')}`;
        const resp = await fetch(`${API}/deals.php?from=${from}&to=${to}`);
        const json = await resp.json();
        deals.value = json.deals || [];
      } finally {
        loadingDeals.value = false;
      }
    }

    async function loadAnalytics() {
      if (!analyticsPeriod.from || !analyticsPeriod.to) return;
      loadingAnalytics.value = true;
      try {
        const resp = await fetch(`${API}/analytics.php?from=${analyticsPeriod.from}&to=${analyticsPeriod.to}`);
        analytics.value = await resp.json();
      } finally {
        loadingAnalytics.value = false;
      }
    }

    function recomputeAnalyticsPeriod() {
      if (analyticsPeriod.mode === 'month') {
        const [y, m] = analyticsPeriod.anchorMonth.split('-').map(Number);
        analyticsPeriod.from = `${y}-${String(m).padStart(2, '0')}-01`;
        const lastDay = new Date(y, m, 0).getDate();
        analyticsPeriod.to = `${y}-${String(m).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
      } else if (analyticsPeriod.mode === 'salary') {
        const [y, m] = analyticsPeriod.anchorMonth.split('-').map(Number);
        analyticsPeriod.from = `${y}-${String(m).padStart(2, '0')}-15`;
        const next = new Date(y, m, 14);  // (m) → следующий месяц (0-indexed), 14 число
        analyticsPeriod.to = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
      }
      // mode==='range' — даты вводятся пользователем вручную
    }

    function setAnalyticsPeriodMode(mode) {
      analyticsPeriod.mode = mode;
      if (mode !== 'range') recomputeAnalyticsPeriod();
    }

    function shiftAnalyticsPeriod(delta) {
      if (analyticsPeriod.mode === 'range') return;
      const [y, m] = analyticsPeriod.anchorMonth.split('-').map(Number);
      const dt = new Date(y, m - 1 + delta, 1);
      analyticsPeriod.anchorMonth = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}`;
      recomputeAnalyticsPeriod();
    }


    // --- modal / form ---
    function openCreateModal() {
      Object.assign(form, emptyForm());
      const today = todayISO();
      form.deal_date = today.startsWith(selectedMonth.value)
        ? today
        : `${selectedMonth.value}-01`;
      formError.value = '';
      modalOpen.value = true;
    }
    function openEditModal(d) {
      Object.assign(form, {
        id: d.id,
        deal_date: d.deal_date,
        direction: d.direction,
        exchange: d.exchange,
        amount_from: Number(d.amount_from),
        amount_to: Number(d.amount_to),
        declared_rate: d.declared_rate !== null && d.declared_rate !== undefined ? Number(d.declared_rate) : null,
        counterparty: d.counterparty || '',
        note: d.note || '',
      });
      formError.value = '';
      modalOpen.value = true;
    }
    function closeModal() { modalOpen.value = false; }

    async function submitForm() {
      formError.value = '';
      saving.value = true;
      try {
        const body = {
          deal_date: form.deal_date,
          direction: form.direction,
          exchange: form.exchange,
          amount_from: form.amount_from,
          amount_to: form.amount_to,
          declared_rate: form.declared_rate || null,
          counterparty: form.counterparty || null,
          note: form.note || null,
        };
        const url = form.id ? `${API}/deals.php?id=${form.id}` : `${API}/deals.php`;
        const method = form.id ? 'PUT' : 'POST';
        const resp = await fetch(url, {
          method, headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
        const json = await resp.json();
        if (!resp.ok) {
          formError.value = json.error || 'Ошибка сохранения';
          return;
        }
        closeModal();
        await loadDeals();
        if (route.name === 'analytics') await loadAnalytics();
      } catch (e) {
        formError.value = e.message || 'Сеть недоступна';
      } finally {
        saving.value = false;
      }
    }

    async function deleteDeal(d) {
      if (!confirm(`Удалить сделку от ${formatDate(d.deal_date)}?`)) return;
      await fetch(`${API}/deals.php?id=${d.id}`, { method: 'DELETE' });
      await loadDeals();
      if (route.name === 'analytics') await loadAnalytics();
    }

    function shiftMonth(delta) {
      const [y, m] = selectedMonth.value.split('-').map(Number);
      const dt = new Date(y, m - 1 + delta, 1);
      selectedMonth.value = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}`;
    }

    async function refreshCalcDefaults() {
      calc.loading = true;
      try {
        const today = todayISO();
        const [ratesResp, dealsResp] = await Promise.all([
          fetch(`${API}/nbu.php?date=${today}`),
          fetch(`${API}/deals.php`),
        ]);
        const rates = await ratesResp.json();
        calc.nbuUsdUah = rates.rates?.USD?.rate_uah ?? null;
        calc.nbuThbUah = rates.rates?.THB?.rate_uah ?? null;

        const allDeals = (await dealsResp.json()).deals || [];
        const lastBuy = allDeals.find(d => d.direction === 'UAH_USDT');
        const lastSell = allDeals.find(d => d.direction === 'USDT_THB');
        calc.lastBuyRate = lastBuy ? Number(lastBuy.declared_rate || lastBuy.rate) : null;
        calc.lastSellRate = lastSell ? Number(lastSell.declared_rate || lastSell.rate) : null;
      } finally {
        calc.loading = false;
      }
    }

    // --- login ---
    async function submitLogin() {
      loginError.value = '';
      loginSubmitting.value = true;
      try {
        const r = await originalFetch(`${API}/login.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ password: loginPassword.value }),
        });
        const j = await r.json();
        if (!r.ok) throw new Error(j.error || 'Ошибка входа');
        authState.authed = true;
        loginPassword.value = '';
        router.push('/deals');
      } catch (e) {
        loginError.value = e.message || 'Сеть недоступна';
      } finally {
        loginSubmitting.value = false;
      }
    }

    async function logout() {
      await originalFetch(`${API}/logout.php`, { method: 'POST' });
      authState.authed = false;
      router.push('/login');
    }

    // --- watchers ---
    watch(selectedMonth, async () => {
      if (isLoginRoute.value) return;
      await loadDeals();
      if (route.name === 'analytics') await loadAnalytics();
    });

    watch(() => route.name, async (name) => {
      if (name === 'login') return;
      if (name === 'deals' && deals.value.length === 0) await loadDeals();
      if (name === 'analytics') {
        if (!analyticsPeriod.from) recomputeAnalyticsPeriod();
        await loadAnalytics();
      }
      if (name === 'calculator' && calc.nbuUsdUah === null) await refreshCalcDefaults();
    });

    watch(() => [analyticsPeriod.from, analyticsPeriod.to, analyticsPeriod.mode], () => {
      if (route.name === 'analytics') loadAnalytics();
    });

    watch(() => analyticsPeriod.anchorMonth, () => {
      if (analyticsPeriod.mode !== 'range') recomputeAnalyticsPeriod();
    });

    onMounted(async () => {
      if (!isLoginRoute.value && authState.authed) {
        await loadDeals();
      }
    });

    return {
      selectedMonth, monthLabel,
      exchanges,
      deals, monthDeals, loadingDeals,
      analytics, loadingAnalytics, buyCount, sellCount,
      buyRateDiffPct, sellRateDiffPct,
      modalOpen, form, formError, saving, computedRate, rateUnit, rateDiffPct, rateDiffAbs,
      openCreateModal, openEditModal, closeModal, submitForm, deleteDeal,
      shiftMonth,
      fromCurrency, toCurrency, directionLabel,
      formatDate, formatAmount, formatRate,
      nbuForDeal, nbuTooltip, rateTooltip,
      calc, calcReady, calcUsdt, calcThb, calcEffectiveUahPerThb, calcNbuCross,
      calcAlternativeUah, calcSavings, calcSavingsPct, calcBreakEvenSellRate,
      refreshCalcDefaults,
      authRequired, logout,
      isLoginRoute, loginPassword, loginPasswordVisible, loginError, loginSubmitting, submitLogin,
      analyticsPeriod, analyticsPeriodLabel, setAnalyticsPeriodMode, shiftAnalyticsPeriod,
    };
  }
});

app.use(router);
app.mount('#app');