/* ==========================================================================
   DYH ERM — Language switching (English / Filipino)
   Strings are written in English in the code and looked up in the Filipino
   dictionary (js/i18n-fil.js). Missing entries fall back to English.
   Placeholders use {name} syntax: t('Hello, {name}', { name: 'Lorna' }).
   ========================================================================== */
(function () {
  'use strict';
  const KEY = 'dyh-erm-lang';
  let lang = 'en';
  try { lang = localStorage.getItem(KEY) || 'en'; } catch (e) { /* storage unavailable */ }

  const I18N = {
    FIL: {},
    get lang() { return lang; },
    set(l) {
      lang = l === 'fil' ? 'fil' : 'en';
      try { localStorage.setItem(KEY, lang); } catch (e) { /* ignore */ }
      document.documentElement.lang = lang === 'fil' ? 'fil' : 'en';
    },
    t(str, vars) {
      let s = lang === 'fil' && I18N.FIL[str] ? I18N.FIL[str] : str;
      if (vars) s = s.replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? vars[k] : m));
      return s;
    },
    /** Pick a localized field from {en, fil} objects. */
    pick(o) { if (!o) return ''; if (typeof o === 'string') return o; return (lang === 'fil' && o.fil) || o.en || ''; },
  };
  document.documentElement.lang = lang === 'fil' ? 'fil' : 'en';
  window.I18N = I18N;
  window.t = I18N.t;
})();
