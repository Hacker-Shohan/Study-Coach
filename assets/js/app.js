// StudyCoach — app.js

document.addEventListener('DOMContentLoaded', () => {

  // ─── Preset minute buttons on log page ───────────────────────────────────
  document.querySelectorAll('.preset-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.querySelector('input[name="minutes"]');
      if (input) input.value = btn.dataset.val;
    });
  });

  // ─── Tone selector radio sync ────────────────────────────────────────────
  document.querySelectorAll('.tone-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tone-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });

  // ─── Auto-dismiss flash alerts ───────────────────────────────────────────
  const flash = document.querySelector('.alert');
  if (flash) {
    setTimeout(() => {
      flash.style.transition = 'opacity 0.5s';
      flash.style.opacity = '0';
      setTimeout(() => flash.remove(), 500);
    }, 4000);
  }

  // ─── Animate stats on page load ──────────────────────────────────────────
  const fills = document.querySelectorAll('.progress-fill');
  fills.forEach(fill => {
    const target = fill.style.width;
    fill.style.width = '0%';
    requestAnimationFrame(() => {
      setTimeout(() => { fill.style.width = target; }, 100);
    });
  });

  // ─── Animate stat values counting up ─────────────────────────────────────
  document.querySelectorAll('.stat-value').forEach(el => {
    const text = el.textContent.trim();
    const num = parseFloat(text);
    if (!isNaN(num) && num > 0 && num < 10000) {
      const isFloat = text.includes('.');
      const prefix = text.replace(/[\d.]+.*/, '');
      const suffix = text.replace(/^[^0-9]*[\d.]+/, '');
      let start = 0;
      const duration = 800;
      const step = (timestamp) => {
        if (!start) start = timestamp;
        const progress = Math.min((timestamp - start) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        const current = eased * num;
        el.textContent = prefix + (isFloat ? current.toFixed(1) : Math.round(current)) + suffix;
        if (progress < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }
  });

  // ─── Bar chart animation ──────────────────────────────────────────────────
  document.querySelectorAll('.chart-bar').forEach((bar, i) => {
    const h = bar.style.height;
    bar.style.height = '4px';
    setTimeout(() => {
      bar.style.transition = 'height 0.5s ease';
      bar.style.height = h;
    }, 100 + i * 60);
  });

  // ─── Heatmap hover tooltips ───────────────────────────────────────────────
  document.querySelectorAll('[title]').forEach(el => {
    el.setAttribute('data-tooltip', el.title);
  });

  // ─── Confirm dangerous actions ────────────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });

});
