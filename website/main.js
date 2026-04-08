/* ── Theme toggle ── */
const html     = document.documentElement;
const themeBtn = document.getElementById('themeBtn');
const saved    = localStorage.getItem('theme') || 'dark';
html.setAttribute('data-theme', saved);

themeBtn.addEventListener('click', () => {
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
});

/* ── Typewriter ── */
const logoEl     = document.getElementById('logoText');
const cursorEl   = document.getElementById('logoCursor');
const subtitleEl = document.getElementById('subtitle');
const ctaGroup   = document.getElementById('ctaGroup');

const LOGO     = 'Vupi.us';
const SUBTITLE = 'Soluções em tecnologia!';

function typewrite(el, text, speed, onDone) {
  let i = 0;
  const tick = () => {
    el.textContent = text.slice(0, ++i);
    if (i < text.length) setTimeout(tick, speed);
    else if (onDone) onDone();
  };
  setTimeout(tick, speed);
}

// Type logo first, then subtitle
typewrite(logoEl, LOGO, 90, () => {
  // hide cursor briefly, then type subtitle
  setTimeout(() => {
    cursorEl.style.display = 'none';
    typewrite(subtitleEl, SUBTITLE, 45, () => {
      cursorEl.style.display = 'inline-block';
      setTimeout(() => ctaGroup.classList.add('visible'), 200);
    });
  }, 300);
});

/* ── Orbs ── */
['orb-1', 'orb-2'].forEach(cls => {
  const el = document.createElement('div');
  el.className = 'orb ' + cls;
  document.body.appendChild(el);
});

/* ── Particle canvas ── */
const canvas = document.getElementById('bg');
const ctx    = canvas.getContext('2d');

let W, H, particles;

function resize() {
  W = canvas.width  = window.innerWidth;
  H = canvas.height = window.innerHeight;
}

function getAccentColor() {
  return getComputedStyle(html).getPropertyValue('--particle').trim();
}

class Particle {
  constructor() { this.reset(true); }

  reset(init = false) {
    this.x  = Math.random() * W;
    this.y  = init ? Math.random() * H : H + 10;
    this.r  = Math.random() * 1.8 + 0.4;
    this.vx = (Math.random() - 0.5) * 0.4;
    this.vy = -(Math.random() * 0.5 + 0.2);
    this.alpha = Math.random() * 0.6 + 0.2;
    this.life  = 0;
    this.maxLife = Math.random() * 300 + 200;
  }

  update() {
    this.x += this.vx;
    this.y += this.vy;
    this.life++;
    if (this.life > this.maxLife || this.y < -10) this.reset();
  }

  draw() {
    const progress = this.life / this.maxLife;
    const fade = progress < 0.1
      ? progress / 0.1
      : progress > 0.8
        ? 1 - (progress - 0.8) / 0.2
        : 1;

    ctx.beginPath();
    ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
    ctx.fillStyle = getAccentColor();
    ctx.globalAlpha = this.alpha * fade;
    ctx.fill();
    ctx.globalAlpha = 1;
  }
}

function initParticles() {
  particles = Array.from({ length: 120 }, () => new Particle());
}

function drawConnections() {
  const color = getAccentColor();
  for (let i = 0; i < particles.length; i++) {
    for (let j = i + 1; j < particles.length; j++) {
      const dx = particles[i].x - particles[j].x;
      const dy = particles[i].y - particles[j].y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < 90) {
        ctx.beginPath();
        ctx.moveTo(particles[i].x, particles[i].y);
        ctx.lineTo(particles[j].x, particles[j].y);
        ctx.strokeStyle = color;
        ctx.globalAlpha = (1 - dist / 90) * 0.18;
        ctx.lineWidth = 0.6;
        ctx.stroke();
        ctx.globalAlpha = 1;
      }
    }
  }
}

function loop() {
  ctx.clearRect(0, 0, W, H);
  drawConnections();
  particles.forEach(p => { p.update(); p.draw(); });
  requestAnimationFrame(loop);
}

resize();
initParticles();
loop();

window.addEventListener('resize', () => { resize(); initParticles(); });
