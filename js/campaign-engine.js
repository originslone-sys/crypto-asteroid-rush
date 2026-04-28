/* ============================================
   UNOBIX - Campaign Engine (protótipo MVP-0)
   File: js/campaign-engine.js

   Versão inicial mínima para validar o gameplay
   nuclear: nave do jogador, parallax 3 camadas,
   1 onda de inimigos descendo, tiro automático,
   colisões e HP. Boss / power-ups / múltiplas ondas
   ficam para próximas iterações.

   API pública (singleton):
     CampaignEngine.start({ canvas, sprites, stage, onEnd })
     CampaignEngine.stop()

     Callback onEnd(result) recebe:
       {
         result: 'win' | 'loss',
         damage_taken: int,
         time_elapsed: int (segundos),
         enemies_destroyed: int,
         max_combo: int
       }
   ============================================ */

(function (global) {
  'use strict';

  // ----------------------------------------------------------------------
  // Configurações default (alinhadas com campaign_settings)
  // Podem ser sobrescritas no start({ config })
  // ----------------------------------------------------------------------
  const DEFAULTS = {
    shipMaxHp: 100,
    shootCooldownMs: 300,
    bulletDamage: 10,
    bulletSpeed: 14,           // px/frame
    enemyMinSpeed: 1.4,
    enemyMaxSpeed: 2.4,
    spawnIntervalMs: 1100,
    parallaxFar: 0.3,
    parallaxMid: 0.7,
    parallaxNear: 1.4,
    comboKillsPerStep: 10,
    comboMax: 5,
  };

  // ----------------------------------------------------------------------
  // Estado interno
  // ----------------------------------------------------------------------
  let canvas, ctx;
  let sprites = null;
  let cfg = { ...DEFAULTS };
  let stage = null;            // { duration_seconds, sector, ... }
  let running = false;
  let rafId = null;

  // Relógio
  let startTimeMs = 0;
  let lastFrameMs = 0;

  // Player
  let player = null;
  let inputKeys = {};
  let pointer = { active: false, x: 0, y: 0 };

  // Mundo
  const playerBullets = [];
  const enemies = [];
  let enemiesDestroyed = 0;
  let maxCombo = 0;
  let combo = 0;
  let damageTaken = 0;
  let nextSpawnAtMs = 0;
  let spawnedTotal = 0;        // limite por fase
  let plannedTotal = 8;        // configurável via stage.waves_json (futuro)

  // Parallax
  let bgFar = null, bgMid = null, bgNear = null;
  let bgFarY = 0, bgMidY = 0, bgNearY = 0;

  // Callback
  let onEndCb = null;
  let endedFired = false;

  // ----------------------------------------------------------------------
  // Pool de skins de inimigos por sector (vem do CampaignAssets)
  // ----------------------------------------------------------------------
  function pickEnemySprite(sector) {
    if (!global.CampaignAssets) return null;
    const list = [
      ...(global.CampaignAssets.byBehavior(sector, 'tank') || []),
      ...(global.CampaignAssets.byBehavior(sector, 'bullet') || []),
    ];
    if (!list.length) return null;
    const key = list[Math.floor(Math.random() * list.length)];
    return global.CampaignAssets.tryGet(key);
  }

  function loadBackgrounds(sector) {
    if (!global.CampaignAssets) return;
    const keys = global.CampaignAssets.backgroundKeys(sector) || {};
    bgFar  = keys.far  ? global.CampaignAssets.tryGet(keys.far)  : null;
    bgMid  = keys.mid  ? global.CampaignAssets.tryGet(keys.mid)  : null;
    bgNear = keys.near ? global.CampaignAssets.tryGet(keys.near) : null;
  }

  // ----------------------------------------------------------------------
  // Player setup
  // ----------------------------------------------------------------------
  function spawnPlayer() {
    const w = 56, h = 56;
    player = {
      x: canvas.width / 2 - w / 2,
      y: canvas.height - 120,
      w, h,
      hp: cfg.shipMaxHp,
      lastShotMs: 0,
      sprite: (global.CampaignAssets && global.CampaignAssets.tryGet('ship_default')) || null,
      bulletSprite: (global.CampaignAssets && global.CampaignAssets.tryGet('player_bullet')) || null,
    };
  }

  // ----------------------------------------------------------------------
  // Spawning
  // ----------------------------------------------------------------------
  function maybeSpawnEnemy(nowMs) {
    if (spawnedTotal >= plannedTotal) return;
    if (nowMs < nextSpawnAtMs) return;
    spawnEnemy();
    spawnedTotal += 1;
    nextSpawnAtMs = nowMs + cfg.spawnIntervalMs;
  }

  function spawnEnemy() {
    const w = 48, h = 48;
    const sector = stage && stage.sector ? stage.sector : 1;
    const speed  = cfg.enemyMinSpeed + Math.random() * (cfg.enemyMaxSpeed - cfg.enemyMinSpeed);
    enemies.push({
      x: 20 + Math.random() * (canvas.width - 20 - w),
      y: -h - 10,
      w, h,
      vy: speed,
      hp: 2,
      damage: 18,
      sprite: pickEnemySprite(sector),
    });
  }

  // ----------------------------------------------------------------------
  // Input
  // ----------------------------------------------------------------------
  function bindInput() {
    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('keyup', onKeyUp);
    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerup', onPointerUp);
    canvas.addEventListener('pointercancel', onPointerUp);
  }
  function unbindInput() {
    document.removeEventListener('keydown', onKeyDown);
    document.removeEventListener('keyup', onKeyUp);
    canvas.removeEventListener('pointerdown', onPointerDown);
    canvas.removeEventListener('pointermove', onPointerMove);
    canvas.removeEventListener('pointerup', onPointerUp);
    canvas.removeEventListener('pointercancel', onPointerUp);
    inputKeys = {};
    pointer = { active: false, x: 0, y: 0 };
  }

  function onKeyDown(e) { inputKeys[e.key.toLowerCase()] = true; }
  function onKeyUp(e)   { inputKeys[e.key.toLowerCase()] = false; }
  function onPointerDown(e) {
    pointer.active = true;
    updatePointer(e);
  }
  function onPointerMove(e) {
    if (!pointer.active) return;
    updatePointer(e);
  }
  function onPointerUp() { pointer.active = false; }
  function updatePointer(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    pointer.x = (e.clientX - rect.left) * scaleX;
    pointer.y = (e.clientY - rect.top) * scaleY;
  }

  // ----------------------------------------------------------------------
  // Update / lógica
  // ----------------------------------------------------------------------
  function updatePlayer(dt) {
    const speed = 5;
    let dx = 0, dy = 0;
    if (inputKeys['arrowleft']  || inputKeys['a']) dx -= speed;
    if (inputKeys['arrowright'] || inputKeys['d']) dx += speed;
    if (inputKeys['arrowup']    || inputKeys['w']) dy -= speed;
    if (inputKeys['arrowdown']  || inputKeys['s']) dy += speed;
    player.x += dx * dt; player.y += dy * dt;

    // Ponteiro: arrasta a nave para a posição central no eixo X (e Y)
    if (pointer.active) {
      const targetX = pointer.x - player.w / 2;
      const targetY = pointer.y - player.h / 2;
      player.x += (targetX - player.x) * 0.25;
      player.y += (targetY - player.y) * 0.25;
    }

    // Limites
    if (player.x < 0) player.x = 0;
    if (player.x + player.w > canvas.width)  player.x = canvas.width - player.w;
    if (player.y < canvas.height * 0.4)       player.y = canvas.height * 0.4;
    if (player.y + player.h > canvas.height)  player.y = canvas.height - player.h;
  }

  function autoShoot(nowMs) {
    if (nowMs - player.lastShotMs < cfg.shootCooldownMs) return;
    player.lastShotMs = nowMs;
    const bw = 8, bh = 22;
    playerBullets.push({
      x: player.x + player.w / 2 - bw / 2,
      y: player.y - bh,
      w: bw, h: bh,
      vy: -cfg.bulletSpeed,
      damage: cfg.bulletDamage,
      sprite: player.bulletSprite,
    });
  }

  function updateBullets(dt) {
    for (let i = playerBullets.length - 1; i >= 0; i--) {
      const b = playerBullets[i];
      b.y += b.vy * dt;
      if (b.y + b.h < 0) playerBullets.splice(i, 1);
    }
  }

  function updateEnemies(dt) {
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      en.y += en.vy * dt;
      if (en.y > canvas.height + 20) {
        enemies.splice(i, 1);
        // saiu da tela viva: zera combo (escapou)
        combo = 0;
      }
    }
  }

  function aabb(a, b) {
    return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
  }

  function handleCollisions() {
    // Bullets vs enemies
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      for (let j = playerBullets.length - 1; j >= 0; j--) {
        const b = playerBullets[j];
        if (!aabb(b, en)) continue;
        en.hp -= b.damage;
        playerBullets.splice(j, 1);
        if (en.hp <= 0) {
          enemies.splice(i, 1);
          enemiesDestroyed += 1;
          combo += 1;
          if (combo > maxCombo) maxCombo = combo;
        }
        break;
      }
    }
    // Enemies vs player
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      if (!aabb(en, player)) continue;
      player.hp -= en.damage;
      damageTaken += en.damage;
      enemies.splice(i, 1);
      combo = 0;
      if (player.hp <= 0) {
        player.hp = 0;
        endRun('loss');
        return;
      }
    }
  }

  function updateParallax(dt) {
    bgFarY  += cfg.parallaxFar  * dt;
    bgMidY  += cfg.parallaxMid  * dt;
    bgNearY += cfg.parallaxNear * dt;
    bgFarY  %= canvas.height;
    bgMidY  %= canvas.height;
    bgNearY %= canvas.height;
  }

  // ----------------------------------------------------------------------
  // Render
  // ----------------------------------------------------------------------
  function drawTiledBackground(img, offsetY) {
    if (!img || !img.complete) return;
    const w = canvas.width, h = canvas.height;
    ctx.drawImage(img, 0, offsetY,         w, h);
    ctx.drawImage(img, 0, offsetY - h,     w, h);
  }

  function drawPlayer() {
    if (player.sprite && player.sprite.complete) {
      ctx.drawImage(player.sprite, player.x, player.y, player.w, player.h);
    } else {
      ctx.fillStyle = '#5cd5ff';
      ctx.fillRect(player.x, player.y, player.w, player.h);
    }
  }

  function drawEnemies() {
    for (const en of enemies) {
      if (en.sprite && en.sprite.complete) {
        ctx.drawImage(en.sprite, en.x, en.y, en.w, en.h);
      } else {
        ctx.fillStyle = '#ff6b6b';
        ctx.fillRect(en.x, en.y, en.w, en.h);
      }
    }
  }

  function drawBullets() {
    for (const b of playerBullets) {
      if (b.sprite && b.sprite.complete) {
        ctx.drawImage(b.sprite, b.x, b.y, b.w, b.h);
      } else {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(b.x, b.y, b.w, b.h);
      }
    }
  }

  function drawHud(timeLeftSec) {
    // HP bar
    const padX = 16, padY = 16, barW = canvas.width - padX * 2, barH = 12;
    ctx.fillStyle = 'rgba(0,0,0,0.45)';
    ctx.fillRect(padX, padY, barW, barH);
    const hpPct = Math.max(0, player.hp / cfg.shipMaxHp);
    const hpColor = hpPct > 0.5 ? '#5fdb91' : (hpPct > 0.25 ? '#ffd166' : '#ff6b6b');
    ctx.fillStyle = hpColor;
    ctx.fillRect(padX, padY, barW * hpPct, barH);
    ctx.strokeStyle = 'rgba(255,255,255,0.45)';
    ctx.strokeRect(padX, padY, barW, barH);

    // Texto
    ctx.fillStyle = '#fff';
    ctx.font = '600 14px ui-monospace,Menlo,monospace';
    ctx.textBaseline = 'top';
    ctx.fillText('HP ' + Math.ceil(player.hp), padX, padY + barH + 6);

    const tStr = (timeLeftSec >= 0 ? timeLeftSec : 0).toString().padStart(2, '0') + 's';
    ctx.textAlign = 'right';
    ctx.fillText(tStr, canvas.width - padX, padY + barH + 6);
    ctx.textAlign = 'left';

    // Combo
    if (combo > 1) {
      ctx.fillStyle = '#ff7eea';
      ctx.font = '700 16px ui-monospace,Menlo,monospace';
      ctx.fillText('x' + combo, padX, padY + barH + 28);
    }
    // Inimigos
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.font = '12px ui-monospace,Menlo,monospace';
    ctx.textAlign = 'right';
    ctx.fillText(enemiesDestroyed + ' / ' + plannedTotal, canvas.width - padX, padY + barH + 28);
    ctx.textAlign = 'left';
  }

  function clear() {
    ctx.fillStyle = '#04060f';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
  }

  // ----------------------------------------------------------------------
  // Game loop
  // ----------------------------------------------------------------------
  function loop(ts) {
    if (!running) return;
    const dtMs = Math.min(50, ts - (lastFrameMs || ts));
    lastFrameMs = ts;
    const dt = dtMs / 16.67;   // normaliza para "1 = um frame de 60fps"

    const elapsedMs = ts - startTimeMs;
    const elapsedSec = Math.floor(elapsedMs / 1000);
    const duration = stage && stage.duration_seconds ? stage.duration_seconds : 60;
    const timeLeft = Math.max(0, duration - elapsedSec);

    updateParallax(dt);
    updatePlayer(dt);
    autoShoot(ts);
    updateBullets(dt);
    maybeSpawnEnemy(ts);
    updateEnemies(dt);
    handleCollisions();

    clear();
    drawTiledBackground(bgFar,  bgFarY);
    drawTiledBackground(bgMid,  bgMidY);
    drawTiledBackground(bgNear, bgNearY);
    drawEnemies();
    drawBullets();
    drawPlayer();
    drawHud(timeLeft);

    // Condições de fim
    if (player.hp <= 0) { endRun('loss'); return; }
    if (timeLeft <= 0 && spawnedTotal >= plannedTotal && enemies.length === 0) {
      endRun('win');
      return;
    }
    if (timeLeft <= 0) {
      // Tempo acabou mas ainda há inimigos: dá uma "graça" curta
      if (elapsedSec > duration + 5) {
        endRun(player.hp > 0 ? 'win' : 'loss');
        return;
      }
    }

    rafId = requestAnimationFrame(loop);
  }

  // ----------------------------------------------------------------------
  // Lifecycle
  // ----------------------------------------------------------------------
  function start(opts) {
    if (running) return;
    canvas = opts.canvas;
    ctx = canvas.getContext('2d');
    stage = opts.stage || { duration_seconds: 60, sector: 1 };
    onEndCb = typeof opts.onEnd === 'function' ? opts.onEnd : null;
    cfg = { ...DEFAULTS, ...(opts.config || {}) };

    // Reset estado
    playerBullets.length = 0;
    enemies.length = 0;
    enemiesDestroyed = 0;
    maxCombo = 0;
    combo = 0;
    damageTaken = 0;
    spawnedTotal = 0;
    plannedTotal = (opts.totalEnemies | 0) || 8;
    bgFarY = bgMidY = bgNearY = 0;
    endedFired = false;

    spawnPlayer();
    loadBackgrounds(stage.sector);
    bindInput();

    running = true;
    startTimeMs = performance.now();
    lastFrameMs = 0;
    nextSpawnAtMs = startTimeMs + 800;
    rafId = requestAnimationFrame(loop);
  }

  function stop() {
    if (!running) return;
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    unbindInput();
  }

  function endRun(result) {
    if (endedFired) return;
    endedFired = true;
    stop();
    const payload = {
      result,
      damage_taken: Math.round(damageTaken),
      time_elapsed: Math.floor((performance.now() - startTimeMs) / 1000),
      enemies_destroyed: enemiesDestroyed,
      max_combo: maxCombo,
    };
    if (onEndCb) onEndCb(payload);
  }

  global.CampaignEngine = { start, stop };
})(window);
