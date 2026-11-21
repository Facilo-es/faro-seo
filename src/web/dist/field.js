/* Faro SEO field: tabs, live Google preview, length bars, checks and share cards. */
(function () {
  'use strict';
  window.Faro = window.Faro || {};

  var t = function (msg, params) { return Craft.t('faro-seo', msg, params); };
  var BANDS = {good: 'Good', fair: 'Needs work', poor: 'Poor'};
  // Rough character guides for the bars; the server measures real pixel widths.
  var LIMITS = {title: 60, description: 158};
  var COLOURS = {green: 'good', amber: 'fair', red: 'poor'};

  Faro.Field = function (id, opts) {
    var root = document.getElementById(id);
    if (!root || root.dataset.faroReady) { return; }
    root.dataset.faroReady = '1';
    this.root = root;
    this.opts = opts || {};
    this.json = root.querySelector('[data-faro-json]');
    this.value = JSON.parse(this.json.value || '{}');
    this.fallback = JSON.parse(root.dataset.fallback || '{}');
    this.timer = null;
    this.bind();
    this.render(null);
    this.preview();
  };

  Faro.Field.prototype = {
    $: function (sel) { return this.root.querySelector(sel); },
    $$: function (sel) { return Array.prototype.slice.call(this.root.querySelectorAll(sel)); },

    bind: function () {
      var self = this;
      this.$$('[data-faro-tab]').forEach(function (a) {
        a.addEventListener('click', function () {
          self.$$('[data-faro-tab]').forEach(function (b) { b.classList.toggle('sel', b === a); });
          self.$$('[data-faro-pane]').forEach(function (p) { p.classList.toggle('hidden', p.dataset.faroPane !== a.dataset.faroTab); });
        });
      });
      this.$$('[data-faro-device]').forEach(function (a) {
        a.addEventListener('click', function () {
          self.$$('[data-faro-device]').forEach(function (b) { b.classList.toggle('sel', b === a); });
          self.$('[data-faro-serp]').classList.toggle('mob', a.dataset.faroDevice === 'mob');
        });
      });
      this.$$('[data-faro-key], [data-faro-robot]').forEach(function (el) {
        el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', function () { self.changed(); });
      });
      // Share images live in Craft's element selects; read them whenever they change.
      ['og', 'x'].forEach(function (net) {
        var box = self.$('[data-faro-net="' + net + '"] .elementselect');
        if (!box) { return; }
        new MutationObserver(function () { self.changed(); }).observe(box, {childList: true, subtree: true});
      });
    },

    read: function () {
      var v = this.value;
      v.social = v.social || {og: {}, x: {}};
      this.$$('[data-faro-key]').forEach(function (el) {
        var path = el.dataset.faroKey.split('.');
        var val = el.value;
        if (path[0] === 'keywords') {
          v.keywords = val.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
          return;
        }
        if (path.length === 3) {
          v.social[path[1]] = v.social[path[1]] || {};
          v.social[path[1]][path[2]] = val;
        } else {
          v[path[0]] = val;
        }
      });
      v.robots = this.$$('[data-faro-robot]').filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.faroRobot; });
      var self = this;
      ['og', 'x'].forEach(function (net) {
        var el = self.$('[data-faro-net="' + net + '"] .elementselect [data-id]');
        v.social[net] = v.social[net] || {};
        v.social[net].imageId = el ? parseInt(el.dataset.id, 10) : null;
        var img = el ? el.querySelector('img') : null;
        self.opts.images = self.opts.images || {};
        self.opts.images[net] = img ? (img.currentSrc || img.src) : (el ? self.opts.images[net] : null);
      });
      // Only touch the form once the author has changed something, or Craft
      // sees a change on load and makes a draft.
      if (this.dirty) { this.json.value = JSON.stringify(v); }
      return v;
    },

    changed: function () {
      this.dirty = true;
      this.read();
      this.render(null);
      clearTimeout(this.timer);
      this.timer = setTimeout(this.preview.bind(this), 600);
    },

    render: function (data) {
      var v = this.value;
      var title = (data && data.titleCut) || v.title || this.fallback.title || '';
      var desc = (data && data.descriptionCut) || v.description || this.fallback.description || '';
      this.$('[data-faro-pv="title"]').textContent = title;
      this.$('[data-faro-pv="description"]').textContent = desc;
      this.bar('title', (data && data.title) || v.title || this.fallback.title || '', data && data.titleBand);
      this.bar('description', (data && data.description) || v.description || this.fallback.description || '', data && data.descriptionBand);
      this.cards(title, desc);
    },

    bar: function (key, text, band) {
      var box = this.$('[data-faro-len="' + key + '"]');
      if (!box) { return; }
      var n = text.length, max = LIMITS[key];
      band = COLOURS[band] || band;
      if (!band) {
        band = n === 0 ? 'poor' : (n > max ? 'poor' : (n < max * 0.5 ? 'fair' : 'good'));
      }
      box.className = 'faro-len ' + band;
      box.firstElementChild.style.width = Math.min(100, Math.round(n / max * 100)) + '%';
      this.$('[data-faro-count="' + key + '"]').textContent = t('{n} of {max} characters', {n: n, max: max});
    },

    cards: function (title, desc) {
      var v = this.value, o = this.opts, s = v.social || {};
      this.$$('[data-faro-card]').forEach(function (card) {
        var net = card.dataset.faroCard, mine = s[net] || {}, og = s.og || {};
        // X falls back to the Facebook values, then to search, as the meta output does.
        card.querySelector('b').textContent = mine.title || og.title || title;
        card.querySelector('p').textContent = mine.description || og.description || desc;
        var img = (o.images && (o.images[net] || o.images.og)) || o.fallbackImage;
        card.querySelector('.img').style.backgroundImage = img ? 'url("' + img.replace(/"/g, '%22') + '")' : '';
      });
    },

    preview: function () {
      var self = this, id = this.root.dataset.elementId;
      if (!id) { return; }
      Craft.sendActionRequest('POST', this.opts.previewAction, {
        data: {elementId: id, siteId: this.root.dataset.siteId, value: this.read()},
      }).then(function (res) {
        self.render(res.data);
        self.checks(res.data);
      }).catch(function () {
        self.$('[data-faro-checks]').innerHTML = '<li class="faro-placeholder">' + Craft.escapeHtml(t('Couldn’t check the page. Save it and try again.')) + '</li>';
      });
    },

    checks: function (data) {
      var list = this.$('[data-faro-checks]');
      list.innerHTML = (data.checks || []).map(function (c) {
        var band = c.status === 'good' ? 'good' : (c.status === 'fair' ? 'fair' : 'poor');
        return '<li><span class="faro-dot ' + band + '"></span><span>' + Craft.escapeHtml(c.text) + '</span></li>';
      }).join('');
      var score = data.score, band = score >= 80 ? 'good' : (score >= 50 ? 'fair' : 'poor');
      this.value.score = score;
      if (this.dirty) { this.json.value = JSON.stringify(this.value); }
      this.$('[data-faro-score]').innerHTML = '<span class="faro-dot ' + band + '"></span><span>' + Craft.escapeHtml(t(BANDS[band])) + '</span>';
    },
  };
})();
