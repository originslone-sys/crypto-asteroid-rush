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
    bulletSpeed: 14,
    enemyBulletSpeed: 6,
    parallaxFar: 0.3,
    parallaxMid: 0.7,
    parallaxNear: 1.4,
    comboKillsPerStep: 10,
    comboMax: 5,
    powerupDropChance: 0.06,
    powerupFallSpeed: 1.6,
  };

  // ----------------------------------------------------------------------
  // Catálogo de comportamentos de inimigos
  // Cada behavior define stats + função update(en, ctx)
  // ----------------------------------------------------------------------
  const BEHAVIORS = {
    tank:     { hp: 3, damage: 25, w: 56, h: 56, baseSpeed: 1.2,
                fireWhileMovingMs: 3500, projDamage: 12, projSpeed: 4 },
    bullet:   { hp: 1, damage: 10, w: 44, h: 44, baseSpeed: 2.4 },
    kamikaze: { hp: 2, damage: 30, w: 50, h: 50, baseSpeed: 1.6,
                fireWhileMovingMs: 2800, projDamage: 12, projSpeed: 5,
                aimAtPlayer: true },
    shooter:  { hp: 4, damage: 15, w: 52, h: 52, baseSpeed: 1.0,
                stopY: 180, fireIntervalMs: 1500 },
    dodger:   { hp: 2, damage: 20, w: 48, h: 48, baseSpeed: 1.8,
                amplitude: 60, frequency: 0.05,
                fireWhileMovingMs: 2500, projDamage: 12, projSpeed: 5,
                aimAtPlayer: true },
    miniboss: { hp: 15, damage: 40, w: 88, h: 88, baseSpeed: 0.6,
                stopY: 160, fireIntervalMs: 2000, projectiles: 2, projDamage: 18,
                amplitude: 100, frequency: 0.0015,
                guaranteedDrop: true, xpBonus: 50 },
  };

  // ----------------------------------------------------------------------
  // Catálogo de power-ups
  // - 'shield' / 'repair' / 'bomb': aplicação instantânea
  // - 'triple_shot' / 'slow_time': efeito com duração (ms)
  // ----------------------------------------------------------------------
  // Power-ups são desenhados proceduralmente via canvas (drawPowerupIcon).
  // Não dependem de PNG no servidor, então nunca quebram por cache/404.
  const POWERUPS = {
    shield:      { icon: 'shield', timed: false, color: '#5cd5ff' },
    repair:      { icon: 'repair', timed: false, color: '#ff5566', amount: 25 },
    bomb:        { icon: 'bomb',   timed: false, color: '#ff7e4a' },
    triple_shot: { icon: 'triple', timed: true,  color: '#ffd166', durationMs: 10000 },
    slow_time:   { icon: 'clock',  timed: true,  color: '#a0aaff', durationMs: 5000, slowFactor: 0.5 },
  };
  const POWERUP_KEYS = Object.keys(POWERUPS);

  // ----------------------------------------------------------------------
  // Catálogo de bosses
  // ----------------------------------------------------------------------
  const BOSSES = {
    asteroid_mother: {
      spriteKey: 'boss_asteroid_mother',
      hp: 500,
      w: 160, h: 160,
      enterY: 110,
      oscAmpBase: 110,
      oscFreqBase: 0.0009,
      contactDamage: 30,
      phase1: { fireMs: 2500, projectiles: 1, projDamage: 15, projSpeed: 4,   fanAngle: 0 },
      phase2: {
        fireMs: 2200, projectiles: 2, projDamage: 15, projSpeed: 4.5, fanAngle: 0.18,
        orbitalMinions: 2, minionHp: 5, minionDamage: 25, minionRadius: 90, minionSpeed: 0.06,
      },
      phase3: {
        fireMs: 1800, projectiles: 3, projDamage: 15, projSpeed: 5,   fanAngle: 0.18,
        orbitalMinions: 2, minionHp: 5, minionDamage: 25, minionRadius: 90, minionSpeed: 0.06,
        chargeMs: 8000, chargeSpeed: 7,
      },
      drops: ['repair', 'bomb'],
      brlExtra: 0, xpExtra: 0,
    },
    junk_devourer: {
      spriteKey: 'boss_junk_devourer',
      hp: 1000,
      w: 192, h: 160,
      enterY: 90,
      oscAmpBase: 90,
      oscFreqBase: 0.0011,
      contactDamage: 35,
      // Fase 1 (HP 100-50%): leque de 5 projéteis
      phase1: {
        fireMs: 2200, projectiles: 5, projDamage: 15, projSpeed: 4.5, fanAngle: 0.42,
      },
      // Fase 2 (HP 50-25%): + spawn de 2 atiradores como minions a cada 10s
      phase2: {
        fireMs: 1900, projectiles: 5, projDamage: 15, projSpeed: 5,   fanAngle: 0.5,
        spawnShooterMs: 10000, shooterCount: 2,
      },
      // Fase 3 (<25%): + laser sweep horizontal
      phase3: {
        fireMs: 1700, projectiles: 7, projDamage: 18, projSpeed: 5.5, fanAngle: 0.7,
        spawnShooterMs: 8000, shooterCount: 2,
        laserMs: 9000, laserWindupMs: 1000, laserActiveMs: 600, laserDamage: 30,
      },
      drops: ['repair', 'bomb', 'shield'],
      brlExtra: 0, xpExtra: 0,
    },
  };

  // ----------------------------------------------------------------------
  // Estado interno
  // ----------------------------------------------------------------------
  let canvas, ctx;
  let sprites = null;
  let cfg = { ...DEFAULTS };
  let stage = null;            // { duration_seconds, sector, ... }
  let running = false;
  let paused  = false;
  let pausedAtMs = 0;
  let pauseOffsetMs = 0;       // ms acumulados em pausa, descontados do timer
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
  const enemyBullets = [];
  const enemies = [];
  const particles = [];
  const powerups = [];           // [{ x, y, w, h, vy, type, sprite }]
  let enemiesDestroyed = 0;
  let maxCombo = 0;
  let combo = 0;
  let damageTaken = 0;
  let endGuaranteedDropFired = false;

  // Efeitos ativos no jogador
  const effects = {
    shield: false,                // bool — absorve próximo dano
    tripleShotUntil: 0,           // timestamp ms (0 = inativo)
    slowTimeUntil: 0,             // timestamp ms
  };

  // Screen-shake + damage flash (feedback de dano)
  let shakeUntilMs = 0;
  let shakeIntensity = 0;
  let damageFlashUntilMs = 0;
  let bossDeathFlashUntilMs = 0;
  let bossDeathSlowmoUntilMs = 0;

  function triggerShake(intensity, durationMs) {
    const now = performance.now();
    shakeUntilMs = Math.max(shakeUntilMs, now + durationMs);
    shakeIntensity = Math.max(shakeIntensity, intensity);
  }

  // Sistema de ondas
  let waveQueue = [];          // [{ duration_max, clear_at, queue: [{behavior, dueAt}] }]
  let activeWave = null;
  let waveIndex = 0;
  let waveStartMs = 0;
  let plannedTotal = 0;        // soma de inimigos previstos no stage

  // Boss
  let bossSpec = null;          // entrada do BOSSES selecionada
  let boss = null;              // instância em cena: { x, y, w, h, hp, hpMax, sprite, ... }
  let bossPhase = 0;            // 0 = ainda não apareceu, 1/2/3 conforme HP
  let bossWarningUntil = 0;     // timestamp ms; antes disso, mostra "WARNING"
  let bossEndedFired = false;
  const bossMinions = [];       // mini-asteroides orbitantes da fase 2+

  // Parallax
  let bgFar = null, bgMid = null, bgNear = null;
  let bgFarY = 0, bgMidY = 0, bgNearY = 0;

  // Callback
  let onEndCb = null;
  let endedFired = false;

  // ----------------------------------------------------------------------
  // Background do parallax (vem do CampaignAssets)
  // ----------------------------------------------------------------------
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
    const shipKey = cfg.shipSpriteKey || 'ship_default';
    player = {
      x: canvas.width / 2 - w / 2,
      y: canvas.height - 120,
      w, h,
      hp: cfg.shipMaxHp,
      lastShotMs: 0,
      sprite: (global.CampaignAssets && global.CampaignAssets.tryGet(shipKey))
              || (global.CampaignAssets && global.CampaignAssets.tryGet('ship_default'))
              || null,
      bulletSprite: (global.CampaignAssets && global.CampaignAssets.tryGet('player_bullet')) || null,
    };
  }

  // ----------------------------------------------------------------------
  // Sistema de Ondas + Spawning
  // ----------------------------------------------------------------------
  function loadWaves(stageData, totalEnemiesFallback) {
    waveQueue.length = 0;
    plannedTotal = 0;

    // Boss config opcional em waves_json.boss = { boss_key, warning_ms }
    bossSpec = null;
    boss = null;
    bossPhase = 0;
    bossWarningUntil = 0;
    bossEndedFired = false;
    bossMinions.length = 0;
    const bossCfg = stageData && stageData.waves_json && stageData.waves_json.boss;
    if (bossCfg && bossCfg.boss_key && BOSSES[bossCfg.boss_key]) {
      bossSpec = {
        ...BOSSES[bossCfg.boss_key],
        warning_ms: bossCfg.warning_ms || 4000,
      };
    }

    const waves = stageData && stageData.waves_json && stageData.waves_json.waves;
    if (Array.isArray(waves) && waves.length) {
      for (const w of waves) {
        const queue = [];
        let cursor = 0;
        for (const spawn of (w.spawns || [])) {
          const interval = spawn.interval || 800;
          const count = spawn.count || 0;
          for (let i = 0; i < count; i++) {
            queue.push({ behavior: spawn.behavior || 'tank', dueAt: cursor });
            cursor += interval;
          }
        }
        // Ordena por dueAt para spawns intercalados quando o admin definir múltiplos
        queue.sort((a, b) => a.dueAt - b.dueAt);
        waveQueue.push({
          duration_max: (w.duration_max || 20) * 1000,
          clear_at:     (w.clear_at     || 15) * 1000,
          queue,
          spawnedCount: 0,
          totalCount:   queue.length,
        });
        plannedTotal += queue.length;
      }
    } else {
      // Fallback: 1 onda de 8 inimigos básicos (tank/bullet aleatório)
      const fallback = (totalEnemiesFallback | 0) || 8;
      const queue = [];
      for (let i = 0; i < fallback; i++) {
        queue.push({
          behavior: i % 2 === 0 ? 'tank' : 'bullet',
          dueAt: i * 900,
        });
      }
      waveQueue.push({
        duration_max: 60000,
        clear_at:     45000,
        queue,
        spawnedCount: 0,
        totalCount:   queue.length,
      });
      plannedTotal = queue.length;
    }

    waveIndex = 0;
    activeWave = waveQueue[0] || null;
  }

  function advanceWave(nowMs) {
    waveIndex += 1;
    activeWave = waveQueue[waveIndex] || null;
    waveStartMs = nowMs;
  }

  function tickWaveSpawn(nowMs) {
    if (!activeWave) return;

    const elapsed = nowMs - waveStartMs;

    // Spawna o que estiver "vencido" no queue da onda atual
    while (activeWave.spawnedCount < activeWave.totalCount &&
           activeWave.queue[activeWave.spawnedCount].dueAt <= elapsed) {
      const item = activeWave.queue[activeWave.spawnedCount];
      spawnEnemy(item.behavior);
      activeWave.spawnedCount += 1;
    }

    // Transição: ondas completas E sem inimigos vivos OU duration_max excedido
    const allSpawned   = activeWave.spawnedCount >= activeWave.totalCount;
    const screenClear  = enemies.length === 0;
    const earlyClear   = allSpawned && screenClear && elapsed >= activeWave.clear_at;
    const timeoutHit   = elapsed >= activeWave.duration_max;

    if (earlyClear || timeoutHit) {
      advanceWave(nowMs);
    }
  }

  function spawnEnemy(behaviorKey) {
    const def = BEHAVIORS[behaviorKey] || BEHAVIORS.tank;
    const sector = (stage && stage.sector) ? stage.sector : 1;
    const sprite = pickSpriteForBehavior(sector, behaviorKey);

    enemies.push({
      behavior: behaviorKey,
      x: 20 + Math.random() * (canvas.width - 40 - def.w),
      y: -def.h - 10,
      w: def.w, h: def.h,
      vx: 0,
      vy: def.baseSpeed * (0.85 + Math.random() * 0.3),
      hp: def.hp,
      hpMax: def.hp,
      damage: def.damage,
      sprite,
      // Campos por behavior
      stopY: def.stopY || null,
      stopped: false,
      lastFireMs: 0,
      fireIntervalMs: def.fireIntervalMs || 1500,
      projectiles: def.projectiles || 1,
      projDamage:  def.projDamage  || 15,
      projSpeed:   def.projSpeed   || 4,
      aimAtPlayer: def.aimAtPlayer || false,
      fireWhileMovingMs: def.fireWhileMovingMs || 0,
      lastMoveFireMs:    -1,  // primeiro tiro depois de spawnar com offset
      // Dodger / miniboss
      birthMs: performance.now(),
      amplitude: def.amplitude || 0,
      frequency: def.frequency || 0,
      anchorX: 0,  // setado no primeiro update
      // Mini-boss
      guaranteedDrop: def.guaranteedDrop || false,
      xpBonus: def.xpBonus || 0,
    });
  }

  function pickSpriteForBehavior(sector, behaviorKey) {
    if (!global.CampaignAssets) return null;
    // Miniboss: usa sprite "shooter" (maior, mais imponente) com tom mais escuro
    if (behaviorKey === 'miniboss') {
      const shooters = global.CampaignAssets.byBehavior(sector, 'shooter') || [];
      if (shooters.length) {
        return global.CampaignAssets.tryGet(shooters[0]);
      }
    }
    const list = global.CampaignAssets.byBehavior(sector, behaviorKey);
    if (list && list.length) {
      const key = list[Math.floor(Math.random() * list.length)];
      return global.CampaignAssets.tryGet(key);
    }
    // Fallback: usa qualquer skin do sector
    const all = ['tank','bullet','kamikaze','shooter','dodger']
      .flatMap(b => global.CampaignAssets.byBehavior(sector, b) || []);
    if (!all.length) return null;
    const key = all[Math.floor(Math.random() * all.length)];
    return global.CampaignAssets.tryGet(key);
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
  // Offset do dedo no touch: nave fica 70px ACIMA do dedo para o
  // jogador conseguir ver a nave e o que está vindo de cima.
  const TOUCH_OFFSET_Y = 70;
  // Smoothing constant — % da distância restante por frame em 60fps.
  // Frame-rate independent via Math.pow(remaining, dt/60).
  const TOUCH_SMOOTH_REMAINING = 0.18;  // alcança ~82% em 1 frame; sente ágil mas suave

  function updatePlayer(dt) {
    // Teclado: vetor unitário normalizado para diagonal (não fica mais rápido na diagonal)
    const speed = 4.5;
    let inX = 0, inY = 0;
    if (inputKeys['arrowleft']  || inputKeys['a']) inX -= 1;
    if (inputKeys['arrowright'] || inputKeys['d']) inX += 1;
    if (inputKeys['arrowup']    || inputKeys['w']) inY -= 1;
    if (inputKeys['arrowdown']  || inputKeys['s']) inY += 1;
    if (inX !== 0 || inY !== 0) {
      const inv = 1 / Math.hypot(inX, inY);
      player.x += inX * inv * speed * dt;
      player.y += inY * inv * speed * dt;
    }

    // Touch: smoothing exponencial frame-rate independent.
    // Nave fica TOUCH_OFFSET_Y px acima do dedo.
    if (pointer.active) {
      const targetX = pointer.x - player.w / 2;
      const targetY = pointer.y - player.h / 2 - TOUCH_OFFSET_Y;
      // alpha = 1 - remaining^(dt/1) → frame-rate independent
      const alpha = 1 - Math.pow(TOUCH_SMOOTH_REMAINING, dt);
      // Cap velocidade máxima por frame (anti-snap de pulsos)
      const maxStep = 14 * dt;  // pixels por unidade de dt
      const desiredDx = (targetX - player.x) * alpha;
      const desiredDy = (targetY - player.y) * alpha;
      const stepLen = Math.hypot(desiredDx, desiredDy);
      if (stepLen > maxStep) {
        const k = maxStep / stepLen;
        player.x += desiredDx * k;
        player.y += desiredDy * k;
      } else {
        player.x += desiredDx;
        player.y += desiredDy;
      }
    }

    // Limites (nave não passa do meio para cima)
    if (player.x < 0) player.x = 0;
    if (player.x + player.w > canvas.width)  player.x = canvas.width - player.w;
    if (player.y < canvas.height * 0.4)       player.y = canvas.height * 0.4;
    if (player.y + player.h > canvas.height)  player.y = canvas.height - player.h;
  }

  function autoShoot(nowMs) {
    if (nowMs - player.lastShotMs < cfg.shootCooldownMs) return;
    player.lastShotMs = nowMs;
    const bw = 8, bh = 22;
    const cx = player.x + player.w / 2 - bw / 2;
    const cy = player.y - bh;

    if (isTripleShotActive(nowMs)) {
      // Leque: -15°, 0°, +15° em relação ao topo
      const speed = cfg.bulletSpeed;
      const angles = [-Math.PI / 12, 0, Math.PI / 12];
      for (const a of angles) {
        playerBullets.push({
          x: cx, y: cy, w: bw, h: bh,
          vx: Math.sin(a) * speed,
          vy: -Math.cos(a) * speed,
          damage: cfg.bulletDamage,
          sprite: player.bulletSprite,
        });
      }
    } else {
      playerBullets.push({
        x: cx, y: cy, w: bw, h: bh,
        vx: 0,
        vy: -cfg.bulletSpeed,
        damage: cfg.bulletDamage,
        sprite: player.bulletSprite,
      });
    }
  }

  function updateBullets(dt) {
    for (let i = playerBullets.length - 1; i >= 0; i--) {
      const b = playerBullets[i];
      if (b.vx) b.x += b.vx * dt;
      b.y += b.vy * dt;
      if (b.y + b.h < 0 || b.x < -40 || b.x > canvas.width + 40) {
        playerBullets.splice(i, 1);
      }
    }
  }

  function updateEnemies(dt, nowMs) {
    const slow = isSlowTimeActive(nowMs) ? POWERUPS.slow_time.slowFactor : 1;
    const dtSlow = dt * slow;
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      updateEnemyByBehavior(en, dtSlow, nowMs);
      // Tiro em movimento (tank/kamikaze/dodger atiram enquanto descem)
      maybeFireWhileMoving(en, nowMs);
      if (en.y > canvas.height + 30) {
        enemies.splice(i, 1);
        combo = 0;  // escapou da tela: penaliza combo
      }
    }
  }

  function maybeFireWhileMoving(en, nowMs) {
    if (!en.fireWhileMovingMs) return;
    if (en.y < 30) return;                        // ainda fora da tela
    if (en.y > canvas.height - 100) return;       // perto demais do player, injusto
    if (en.lastMoveFireMs < 0) {
      // Primeiro tiro: random offset 30-80% do intervalo
      en.lastMoveFireMs = nowMs - en.fireWhileMovingMs * (0.3 + Math.random() * 0.5);
    }
    if (nowMs - en.lastMoveFireMs < en.fireWhileMovingMs) return;
    en.lastMoveFireMs = nowMs;
    fireEnemyShot(en);
  }

  function fireEnemyShot(en) {
    const fromX = en.x + en.w / 2 - 4;
    const fromY = en.y + en.h - 4;
    const speed = en.projSpeed;
    let vx = 0, vy = speed;
    if (en.aimAtPlayer && player) {
      const tx = player.x + player.w / 2;
      const ty = player.y + player.h / 2;
      const dx = tx - fromX, dy = ty - fromY;
      const len = Math.hypot(dx, dy) || 1;
      vx = (dx / len) * speed;
      vy = (dy / len) * speed;
    }
    enemyBullets.push({
      x: fromX, y: fromY,
      w: 8, h: 18,
      vx, vy,
      damage: en.projDamage,
      sprite: (global.CampaignAssets && global.CampaignAssets.tryGet('enemy_bullet')) || null,
    });
  }

  function updateEnemyByBehavior(en, dt, nowMs) {
    switch (en.behavior) {
      case 'miniboss': {
        // Entra do topo até stopY oscilando, depois oscila + dispara
        if (en.y < en.stopY) {
          en.y += en.vy * dt;
        } else {
          if (!en.anchorX) en.anchorX = canvas.width / 2 - en.w / 2;
          const t = (nowMs - en.birthMs) * en.frequency;
          en.x = en.anchorX + Math.sin(t) * en.amplitude;
          if (en.x < 8) en.x = 8;
          if (en.x + en.w > canvas.width - 8) en.x = canvas.width - 8 - en.w;
          // Dispara em leque ao player
          if (nowMs - en.lastFireMs > en.fireIntervalMs) {
            en.lastFireMs = nowMs;
            fireMinibossSpread(en);
          }
        }
        break;
      }
      case 'kamikaze': {
        en.y += en.vy * dt;
        if (en.y > 0 && player) {
          const targetX = player.x + player.w / 2 - en.w / 2;
          const dx = targetX - en.x;
          const lerp = Math.max(-2.2, Math.min(2.2, dx * 0.05));
          en.x += lerp * dt;
        }
        break;
      }
      case 'shooter': {
        if (!en.stopped) {
          en.y += en.vy * dt;
          if (en.stopY !== null && en.y >= en.stopY) {
            en.stopped = true;
            en.lastFireMs = nowMs;
          }
        } else {
          // Dispara periodicamente em direção ao jogador
          if (nowMs - en.lastFireMs >= en.fireIntervalMs) {
            spawnEnemyBullet(en);
            en.lastFireMs = nowMs;
          }
        }
        break;
      }
      case 'dodger': {
        if (!en.anchorX) en.anchorX = en.x;
        en.y += en.vy * dt;
        const t = (nowMs - en.birthMs) * en.frequency;
        en.x = en.anchorX + Math.sin(t) * en.amplitude;
        // Mantém dentro do canvas
        if (en.x < 4) en.x = 4;
        if (en.x + en.w > canvas.width - 4) en.x = canvas.width - 4 - en.w;
        break;
      }
      case 'bullet':
      case 'tank':
      default:
        en.y += en.vy * dt;
        break;
    }
  }

  function fireMinibossSpread(en) {
    if (!player) return;
    const fromX = en.x + en.w / 2;
    const fromY = en.y + en.h - 4;
    const tx = player.x + player.w / 2;
    const ty = player.y + player.h / 2;
    const baseAng = Math.atan2(ty - fromY, tx - fromX);
    const speed = cfg.enemyBulletSpeed * 1.2;
    const n = en.projectiles || 2;
    for (let i = 0; i < n; i++) {
      const offset = n === 1 ? 0 : (i - (n - 1) / 2) * 0.18;
      const ang = baseAng + offset;
      enemyBullets.push({
        x: fromX - 6, y: fromY,
        w: 12, h: 22,
        vx: Math.cos(ang) * speed,
        vy: Math.sin(ang) * speed,
        damage: en.projDamage || 18,
        sprite: (global.CampaignAssets && global.CampaignAssets.tryGet('enemy_bullet')) || null,
      });
    }
    spawnExplosion(fromX, fromY, '#ff7e4a');
  }

  function spawnEnemyBullet(en) {
    const bw = 8, bh = 22;
    const fromX = en.x + en.w / 2 - bw / 2;
    const fromY = en.y + en.h;
    // Vetor em direção ao jogador
    let vx = 0, vy = cfg.enemyBulletSpeed;
    if (player) {
      const tx = player.x + player.w / 2 - fromX;
      const ty = player.y + player.h / 2 - fromY;
      const len = Math.hypot(tx, ty) || 1;
      vx = (tx / len) * cfg.enemyBulletSpeed;
      vy = (ty / len) * cfg.enemyBulletSpeed;
    }
    enemyBullets.push({
      x: fromX, y: fromY, w: bw, h: bh, vx, vy,
      damage: 15,
      sprite: (global.CampaignAssets && global.CampaignAssets.tryGet('enemy_bullet')) || null,
    });
  }

  function updateEnemyBullets(dt) {
    for (let i = enemyBullets.length - 1; i >= 0; i--) {
      const b = enemyBullets[i];
      b.x += b.vx * dt;
      b.y += b.vy * dt;
      if (b.y > canvas.height + 20 || b.y < -20 || b.x < -20 || b.x > canvas.width + 20) {
        enemyBullets.splice(i, 1);
      }
    }
  }

  // ----------------------------------------------------------------------
  // Power-ups: drop, queda, coleta e efeitos
  // ----------------------------------------------------------------------
  function maybeDropPowerup(x, y) {
    if (Math.random() > cfg.powerupDropChance) return;
    spawnPowerup(x, y, POWERUP_KEYS[Math.floor(Math.random() * POWERUP_KEYS.length)]);
  }

  function spawnPowerup(x, y, type) {
    const def = POWERUPS[type];
    if (!def) return;
    const w = 36, h = 36;
    powerups.push({
      x: x - w / 2,
      y: y - h / 2,
      w, h,
      vy: cfg.powerupFallSpeed,
      type,
    });
  }

  function updatePowerups(dt) {
    for (let i = powerups.length - 1; i >= 0; i--) {
      const p = powerups[i];
      p.y += p.vy * dt;
      if (p.y > canvas.height + 40) powerups.splice(i, 1);
    }
  }

  function handlePowerupPickup(nowMs) {
    for (let i = powerups.length - 1; i >= 0; i--) {
      const p = powerups[i];
      if (!aabb(p, player)) continue;
      applyPowerup(p.type, nowMs);
      spawnExplosion(p.x + p.w / 2, p.y + p.h / 2, POWERUPS[p.type].color);
      powerups.splice(i, 1);
    }
  }

  function applyPowerup(type, nowMs) {
    const def = POWERUPS[type];
    if (!def) return;
    switch (type) {
      case 'shield':
        effects.shield = true;
        break;
      case 'repair':
        player.hp = Math.min(cfg.shipMaxHp, player.hp + (def.amount || 25));
        break;
      case 'bomb':
        applyBomb();
        break;
      case 'triple_shot':
        effects.tripleShotUntil = nowMs + def.durationMs;
        break;
      case 'slow_time':
        effects.slowTimeUntil = nowMs + def.durationMs;
        break;
    }
  }

  function applyBomb() {
    // Destrói todos os inimigos visíveis e remove projéteis inimigos
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      spawnExplosion(en.x + en.w / 2, en.y + en.h / 2, '#ff7e4a');
      enemiesDestroyed += 1;
      combo += 1;
      if (combo > maxCombo) maxCombo = combo;
    }
    enemies.length = 0;
    enemyBullets.length = 0;
  }

  function isTripleShotActive(nowMs) { return nowMs < effects.tripleShotUntil; }
  function isSlowTimeActive(nowMs)   { return nowMs < effects.slowTimeUntil; }

  // ----------------------------------------------------------------------
  // BOSS — spawn, update, render
  // ----------------------------------------------------------------------
  function maybeStartBoss(nowMs) {
    if (!bossSpec) return;
    if (boss || bossWarningUntil > 0) return;        // já iniciado
    if (activeWave !== null) return;                  // ainda há ondas
    if (enemies.length > 0 || enemyBullets.length > 0) return; // tela suja
    bossWarningUntil = nowMs + bossSpec.warning_ms;
  }

  function trySpawnBoss(nowMs) {
    if (!bossSpec || boss) return;
    if (bossWarningUntil === 0 || nowMs < bossWarningUntil) return;
    boss = {
      x: canvas.width / 2 - bossSpec.w / 2,
      y: -bossSpec.h - 20,
      w: bossSpec.w, h: bossSpec.h,
      hp: bossSpec.hp,
      hpMax: bossSpec.hp,
      sprite: (global.CampaignAssets && global.CampaignAssets.tryGet(bossSpec.spriteKey)) || null,
      enteringTopAt: bossSpec.enterY,
      birthMs: nowMs,
      lastFireMs: 0,
      lastChargeMs: 0,
      charging: false,
      chargeAtY: null,
      chargeReturning: false,
      anchorX: canvas.width / 2 - bossSpec.w / 2,
    };
    bossPhase = 1;
  }

  function currentBossPhase() {
    if (!boss || !bossSpec) return 0;
    const pct = boss.hp / boss.hpMax;
    if (pct <= 0.25) return 3;
    if (pct <= 0.50) return 2;
    return 1;
  }

  function updateBoss(dt, nowMs) {
    if (!boss) return;
    const slow = isSlowTimeActive(nowMs) ? POWERUPS.slow_time.slowFactor : 1;
    const dts = dt * slow;

    // Entrada (desce do topo até enterY)
    if (boss.y < boss.enteringTopAt) {
      boss.y = Math.min(boss.enteringTopAt, boss.y + 1.6 * dt);
      return;
    }

    bossPhase = currentBossPhase();

    // Charge attack (fase 3)
    if (bossPhase >= 3 && !boss.charging && (nowMs - boss.lastChargeMs) > bossSpec.phase3.chargeMs && boss.lastChargeMs >= 0) {
      boss.charging = true;
      boss.chargeAtY = canvas.height - 200;  // alvo
      boss.chargeReturning = false;
      boss.lastChargeMs = nowMs;
    }
    if (boss.charging) {
      const speed = bossSpec.phase3.chargeSpeed;
      if (!boss.chargeReturning) {
        boss.y += speed * dt;
        if (boss.y >= boss.chargeAtY) boss.chargeReturning = true;
      } else {
        boss.y -= speed * 0.7 * dt;
        if (boss.y <= boss.enteringTopAt) {
          boss.y = boss.enteringTopAt;
          boss.charging = false;
        }
      }
      // Mantém oscilação suave em x mesmo no charge
    }

    // Oscilação lateral (varia com a fase)
    const phase = bossPhase || 1;
    const amp = bossSpec.oscAmpBase * (phase === 1 ? 0.85 : phase === 2 ? 1.0 : 1.2);
    const freq = bossSpec.oscFreqBase * (phase === 1 ? 1 : phase === 2 ? 1.4 : 1.8);
    const t = (nowMs - boss.birthMs) * freq * slow;
    boss.x = Math.max(8, Math.min(canvas.width - boss.w - 8,
      (canvas.width / 2 - boss.w / 2) + Math.sin(t) * amp));

    // Disparos por fase
    bossFire(nowMs, phase);

    // Minions orbitantes (Asteroide-Mãe) na fase 2+
    if (phase >= 2 && bossMinions.length === 0) {
      spawnBossMinions();
    }
    updateBossMinions(dts, nowMs);

    // Spawn periódico de inimigos atiradores (Devorador) na fase 2+
    bossSpawnShooterMinions(nowMs, phase);

    // Laser sweep (fase 3 do Devorador)
    bossLaserUpdate(dt, nowMs, phase);
  }

  // ----------------------------------------------------------------------
  // Laser sweep (Devorador, fase 3)
  // ----------------------------------------------------------------------
  function bossLaserUpdate(dt, nowMs, phase) {
    if (phase < 3 || !bossSpec.phase3 || !bossSpec.phase3.laserMs) return;
    const cfg3 = bossSpec.phase3;
    if (boss.laserState == null) {
      boss.laserState = 'idle';
      boss.laserStartMs = nowMs;
      boss.laserY = 0;
      boss.laserDamageApplied = false;
    }
    const elapsed = nowMs - boss.laserStartMs;
    if (boss.laserState === 'idle') {
      if (elapsed >= cfg3.laserMs) {
        boss.laserState = 'windup';
        boss.laserStartMs = nowMs;
        boss.laserY = boss.y + boss.h - 10;   // sai logo abaixo do boss
        boss.laserDamageApplied = false;
      }
    } else if (boss.laserState === 'windup') {
      if (elapsed >= cfg3.laserWindupMs) {
        boss.laserState = 'firing';
        boss.laserStartMs = nowMs;
      }
    } else if (boss.laserState === 'firing') {
      if (elapsed >= cfg3.laserActiveMs) {
        boss.laserState = 'idle';
        boss.laserStartMs = nowMs;
      } else {
        // Damage check: feixe horizontal de altura ~24px na laserY
        if (!boss.laserDamageApplied && rectsHit(boss.laserY, 24, player)) {
          applyDamage(cfg3.laserDamage);
          boss.laserDamageApplied = true;
          if (player.hp <= 0) endRun('loss');
        }
      }
    }
  }

  function rectsHit(beamY, beamH, p) {
    return p.y < beamY + beamH && p.y + p.h > beamY;
  }

  function drawBossLaser(nowMs) {
    if (!boss || !boss.laserState || boss.laserState === 'idle') return;
    const elapsed = nowMs - boss.laserStartMs;
    const cfg3 = bossSpec.phase3;
    if (boss.laserState === 'windup') {
      // Linha vermelha pulsante de aviso
      const pulse = 0.4 + 0.5 * Math.sin(nowMs * 0.025);
      ctx.fillStyle = 'rgba(255,40,40,' + (0.18 + pulse * 0.25) + ')';
      ctx.fillRect(0, boss.laserY - 1, canvas.width, 4);
    } else if (boss.laserState === 'firing') {
      // Feixe sólido com glow
      const grad = ctx.createLinearGradient(0, boss.laserY - 12, 0, boss.laserY + 36);
      grad.addColorStop(0, 'rgba(255,200,100,0)');
      grad.addColorStop(0.45, 'rgba(255,200,100,0.7)');
      grad.addColorStop(0.5, '#fff');
      grad.addColorStop(0.55, 'rgba(255,80,40,0.9)');
      grad.addColorStop(1, 'rgba(255,80,40,0)');
      ctx.fillStyle = grad;
      ctx.fillRect(0, boss.laserY - 12, canvas.width, 48);
    }
  }

  function bossSpawnShooterMinions(nowMs, phase) {
    const cfgPhase = phase >= 3 ? bossSpec.phase3 : phase >= 2 ? bossSpec.phase2 : null;
    if (!cfgPhase || !cfgPhase.spawnShooterMs) return;
    if (boss.lastShooterSpawnMs == null) boss.lastShooterSpawnMs = nowMs;  // primeira chamada arma o timer
    if (nowMs - boss.lastShooterSpawnMs < cfgPhase.spawnShooterMs) return;
    boss.lastShooterSpawnMs = nowMs;
    const n = cfgPhase.shooterCount || 2;
    for (let i = 0; i < n; i++) {
      spawnEnemy('shooter');
    }
  }

  function bossFire(nowMs, phase) {
    const cfgPhase = phase >= 3 ? bossSpec.phase3 : phase >= 2 ? bossSpec.phase2 : bossSpec.phase1;
    if (nowMs - boss.lastFireMs < cfgPhase.fireMs) return;
    boss.lastFireMs = nowMs;

    const projN = cfgPhase.projectiles;
    const projSpeed = cfgPhase.projSpeed;
    const projDmg = cfgPhase.projDamage;
    const fromX = boss.x + boss.w / 2;
    const fromY = boss.y + boss.h - 8;

    // Fan: simétrico em torno do alvo (player)
    const tx = player.x + player.w / 2;
    const ty = player.y + player.h / 2;
    const baseAng = Math.atan2(ty - fromY, tx - fromX);

    const fanAngle = (typeof cfgPhase.fanAngle === 'number') ? cfgPhase.fanAngle : 0.18;
    const stepAngle = projN === 1 ? 0 : fanAngle / (projN - 1);
    for (let i = 0; i < projN; i++) {
      const offset = projN === 1 ? 0 : (i - (projN - 1) / 2) * stepAngle * 2; // espalha total = fanAngle
      const ang = baseAng + offset;
      const sprite = pickSpriteForBehavior(stage.sector || 1, 'tank');  // mini-rocha do setor
      enemyBullets.push({
        x: fromX - 12, y: fromY,
        w: 22, h: 22,
        vx: Math.cos(ang) * projSpeed,
        vy: Math.sin(ang) * projSpeed,
        damage: projDmg,
        sprite: sprite || ((global.CampaignAssets && global.CampaignAssets.tryGet('enemy_bullet')) || null),
      });
    }

    // Pequeno flash de telegrafia
    spawnExplosion(fromX, fromY, '#ffd166');
  }

  function spawnBossMinions() {
    const cfg2 = bossSpec.phase2;
    const n = cfg2 && cfg2.orbitalMinions;
    if (!n) return;
    for (let i = 0; i < n; i++) {
      const sprite = pickSpriteForBehavior(stage.sector || 1, 'bullet');
      bossMinions.push({
        angle: (i / n) * Math.PI * 2,
        radius: cfg2.minionRadius,
        speed: cfg2.minionSpeed,
        w: 36, h: 36,
        x: 0, y: 0,
        hp: cfg2.minionHp,
        damage: cfg2.minionDamage,
        sprite,
      });
    }
  }

  function updateBossMinions(dt, nowMs) {
    if (!boss) return;
    for (let i = bossMinions.length - 1; i >= 0; i--) {
      const m = bossMinions[i];
      m.angle += m.speed * dt;
      const cx = boss.x + boss.w / 2;
      const cy = boss.y + boss.h / 2;
      m.x = cx + Math.cos(m.angle) * m.radius - m.w / 2;
      m.y = cy + Math.sin(m.angle) * m.radius - m.h / 2;
    }
  }

  function drawBossMinions() {
    for (const m of bossMinions) {
      if (m.sprite && m.sprite.complete) {
        ctx.drawImage(m.sprite, m.x, m.y, m.w, m.h);
      } else {
        ctx.fillStyle = '#ff7e4a';
        ctx.fillRect(m.x, m.y, m.w, m.h);
      }
    }
  }

  function handleBossMinionCollisions() {
    if (!boss) return;
    // Player bullets vs minions
    for (let j = playerBullets.length - 1; j >= 0; j--) {
      const b = playerBullets[j];
      for (let i = bossMinions.length - 1; i >= 0; i--) {
        const m = bossMinions[i];
        if (!aabb(b, m)) continue;
        m.hp -= b.damage;
        playerBullets.splice(j, 1);
        spawnExplosion(m.x + m.w / 2, m.y + m.h / 2, '#ffaa3c');
        if (m.hp <= 0) {
          spawnExplosion(m.x + m.w / 2, m.y + m.h / 2, '#ff7e4a');
          bossMinions.splice(i, 1);
          enemiesDestroyed += 1;
          combo += 1;
          if (combo > maxCombo) maxCombo = combo;
          maybeDropPowerup(m.x + m.w / 2, m.y + m.h / 2);
        }
        break;
      }
    }
    // Minions vs player
    for (let i = bossMinions.length - 1; i >= 0; i--) {
      const m = bossMinions[i];
      if (!aabb(m, player)) continue;
      applyDamage(m.damage);
      bossMinions.splice(i, 1);
      if (player.hp <= 0) { endRun('loss'); return; }
    }
  }

  function drawBoss() {
    if (!boss) return;
    if (boss.sprite && boss.sprite.complete) {
      ctx.drawImage(boss.sprite, boss.x, boss.y, boss.w, boss.h);
    } else {
      ctx.fillStyle = '#ff7e4a';
      ctx.fillRect(boss.x, boss.y, boss.w, boss.h);
    }
  }

  function bossTakeDamage(amount) {
    if (!boss) return;
    boss.hp = Math.max(0, boss.hp - amount);
    spawnExplosion(boss.x + boss.w / 2 + (Math.random() - 0.5) * boss.w * 0.4,
                   boss.y + boss.h / 2 + (Math.random() - 0.5) * boss.h * 0.4,
                   bossPhase >= 3 ? '#ff3322' : '#ffaa3c');
    if (boss.hp <= 0) bossDefeated();
  }

  function bossDefeated() {
    if (!boss || bossEndedFired) return;
    bossEndedFired = true;
    const cx = boss.x + boss.w / 2;
    const cy = boss.y + boss.h / 2;
    // Slow-motion + flash branco
    const now = performance.now();
    bossDeathSlowmoUntilMs = now + 1200;
    bossDeathFlashUntilMs = now + 400;
    triggerShake(18, 700);
    // Explosões em cadeia (5 ondas com delays via partículas próprias)
    for (let i = 0; i < 60; i++) {
      const ang = (i / 60) * Math.PI * 2 + Math.random() * 0.3;
      const dist = Math.random() * boss.w * 0.6;
      spawnExplosion(
        cx + Math.cos(ang) * dist,
        cy + Math.sin(ang) * dist,
        ['#ffd166','#ff7e4a','#ff3322','#fff','#5cd5ff'][Math.floor(Math.random() * 5)]
      );
    }
    // Drops garantidos
    if (bossSpec && bossSpec.drops) {
      bossSpec.drops.forEach((type, i) => {
        spawnPowerup(cx + (i - bossSpec.drops.length / 2) * 60, cy, type);
      });
    }
    boss = null;
    bossMinions.length = 0;
    enemyBullets.length = 0;
  }

  function handleBossCollisions() {
    if (!boss) return;
    // Player bullets vs boss
    for (let j = playerBullets.length - 1; j >= 0; j--) {
      const b = playerBullets[j];
      if (!aabb(b, boss)) continue;
      bossTakeDamage(b.damage);
      playerBullets.splice(j, 1);
    }
    // Boss vs player (colisão direta; só durante charge realmente alcança)
    if (aabb(boss, player)) {
      applyDamage(bossSpec.contactDamage);
      // Empurra o jogador para baixo pra evitar dano contínuo
      player.y = Math.min(canvas.height - player.h, player.y + 20);
    }
  }

  /**
   * Quando o jogador acaba de limpar a última onda (sem boss), dropa
   * um Repair garantido no centro-superior. Só dispara uma vez por
   * sessão. Não faz nada em fases de boss (waves vazias / activeWave
   * já foi consumido sem ondas).
   */
  function maybeFireEndOfStageDrop() {
    if (endGuaranteedDropFired) return;
    if (activeWave !== null) return;
    if (enemies.length > 0 || enemyBullets.length > 0) return;
    if (waveQueue.length === 0) return;  // se nunca houve onda, não dropa
    endGuaranteedDropFired = true;
    spawnPowerup(canvas.width / 2, 80, 'repair');
  }

  function updateParticles(dt) {
    for (let i = particles.length - 1; i >= 0; i--) {
      const p = particles[i];
      p.x += p.vx * dt;
      p.y += p.vy * dt;
      p.life -= dt;
      if (p.life <= 0) particles.splice(i, 1);
    }
  }

  function spawnExplosion(x, y, color) {
    const n = 8;
    for (let i = 0; i < n; i++) {
      const ang = (i / n) * Math.PI * 2 + Math.random() * 0.4;
      const sp = 1.5 + Math.random() * 2;
      particles.push({
        x, y,
        vx: Math.cos(ang) * sp,
        vy: Math.sin(ang) * sp,
        life: 18 + Math.random() * 10,
        size: 3 + Math.random() * 2,
        color: color || '#ffaa3c',
      });
    }
  }

  function aabb(a, b) {
    return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
  }

  function handleCollisions() {
    // Player bullets vs enemies
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      for (let j = playerBullets.length - 1; j >= 0; j--) {
        const b = playerBullets[j];
        if (!aabb(b, en)) continue;
        en.hp -= b.damage;
        playerBullets.splice(j, 1);
        spawnExplosion(en.x + en.w / 2, en.y + en.h / 2, '#ffd166');
        if (en.hp <= 0) {
          const cx = en.x + en.w / 2, cy = en.y + en.h / 2;
          // Mini-boss: explosão maior + drop garantido (sorteia entre repair/bomb/shield)
          if (en.guaranteedDrop) {
            // Mini-boss morrendo: shake médio + explosão grande
            triggerShake(8, 250);
            for (let k = 0; k < 16; k++) {
              spawnExplosion(cx + (Math.random() - 0.5) * en.w * 0.9,
                             cy + (Math.random() - 0.5) * en.h * 0.9,
                             ['#ffd166','#ff7e4a','#ff3322','#fff'][Math.floor(Math.random() * 4)]);
            }
            const guaranteedTypes = ['repair', 'bomb', 'shield'];
            const t = guaranteedTypes[Math.floor(Math.random() * guaranteedTypes.length)];
            spawnPowerup(cx, cy, t);
          } else {
            spawnExplosion(cx, cy, '#ff7e4a');
            maybeDropPowerup(cx, cy);
          }
          enemies.splice(i, 1);
          enemiesDestroyed += 1;
          combo += 1;
          if (combo > maxCombo) maxCombo = combo;
        }
        break;
      }
    }
    // Enemies vs player (colisão corpo-a-corpo)
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      if (!aabb(en, player)) continue;
      applyDamage(en.damage);
      spawnExplosion(en.x + en.w / 2, en.y + en.h / 2, '#ff5566');
      enemies.splice(i, 1);
      if (player.hp <= 0) { endRun('loss'); return; }
    }
    // Enemy bullets vs player
    for (let i = enemyBullets.length - 1; i >= 0; i--) {
      const b = enemyBullets[i];
      if (!aabb(b, player)) continue;
      applyDamage(b.damage);
      enemyBullets.splice(i, 1);
      if (player.hp <= 0) { endRun('loss'); return; }
    }
  }

  function applyDamage(amount) {
    if (effects.shield) {
      // Escudo absorve o dano integralmente, mas se consome
      effects.shield = false;
      spawnExplosion(player.x + player.w / 2, player.y + player.h / 2, '#5cd5ff');
      triggerShake(3, 120);   // shake leve pelo escudo
      return;
    }
    player.hp = Math.max(0, player.hp - amount);
    damageTaken += amount;
    combo = 0;
    // Feedback: shake proporcional ao dano + flash vermelho
    const intensity = Math.min(12, 3 + amount * 0.18);
    triggerShake(intensity, 180);
    damageFlashUntilMs = performance.now() + 220;
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
    const flashing = performance.now() < damageFlashUntilMs;
    if (player.sprite && player.sprite.complete) {
      ctx.drawImage(player.sprite, player.x, player.y, player.w, player.h);
      if (flashing) {
        // overlay vermelho na hitbox da nave + tint via globalCompositeOperation
        const a = ((damageFlashUntilMs - performance.now()) / 220);
        ctx.save();
        ctx.globalCompositeOperation = 'source-atop';
        ctx.fillStyle = 'rgba(255, 80, 80, ' + (a * 0.6) + ')';
        ctx.fillRect(player.x, player.y, player.w, player.h);
        ctx.restore();
      }
    } else {
      ctx.fillStyle = flashing ? '#ff5566' : '#5cd5ff';
      ctx.fillRect(player.x, player.y, player.w, player.h);
    }
    // Halo de escudo ativo
    if (effects.shield) {
      ctx.save();
      const t = performance.now() * 0.006;
      const pulse = 1 + Math.sin(t) * 0.08;
      ctx.strokeStyle = 'rgba(92, 213, 255, 0.7)';
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.arc(player.x + player.w / 2, player.y + player.h / 2,
              (player.w / 2 + 6) * pulse, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();
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
      // Barra de HP do miniboss (só quando danificado)
      if (en.behavior === 'miniboss' && en.hp < en.hpMax) {
        const barW = en.w, barH = 4, barY = en.y - 8;
        ctx.fillStyle = 'rgba(0,0,0,0.6)';
        ctx.fillRect(en.x, barY, barW, barH);
        ctx.fillStyle = '#ff7e4a';
        ctx.fillRect(en.x, barY, barW * (en.hp / en.hpMax), barH);
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
    for (const b of enemyBullets) {
      // Rotaciona o sprite na direção do movimento
      const ang = Math.atan2(b.vy, b.vx) - Math.PI / 2;
      ctx.save();
      ctx.translate(b.x + b.w / 2, b.y + b.h / 2);
      ctx.rotate(ang);
      if (b.sprite && b.sprite.complete) {
        ctx.drawImage(b.sprite, -b.w / 2, -b.h / 2, b.w, b.h);
      } else {
        ctx.fillStyle = '#ffaa00';
        ctx.fillRect(-b.w / 2, -b.h / 2, b.w, b.h);
      }
      ctx.restore();
    }
  }

  // Desenha um ícone procedural de power-up no canvas atual.
  // cx,cy = centro; size = lado do bounding box; color = cor principal; nowMs pra pequenas animações.
  function drawPowerupIcon(ctx, icon, cx, cy, size, color, nowMs) {
    const r = size / 2;

    // Disco de fundo (cápsula brilhante) + borda
    const bg = ctx.createRadialGradient(cx - r * 0.3, cy - r * 0.3, 2, cx, cy, r);
    bg.addColorStop(0, '#ffffff');
    bg.addColorStop(0.35, color);
    bg.addColorStop(1, '#0a0f1a');
    ctx.fillStyle = bg;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();

    ctx.lineWidth = Math.max(1.5, size * 0.06);
    ctx.strokeStyle = '#ffffff';
    ctx.globalAlpha = 0.85;
    ctx.beginPath();
    ctx.arc(cx, cy, r - ctx.lineWidth / 2, 0, Math.PI * 2);
    ctx.stroke();
    ctx.globalAlpha = 1;

    // Ícone branco em cima
    ctx.fillStyle = '#ffffff';
    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = Math.max(2, size * 0.1);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    switch (icon) {
      case 'shield': {
        // Escudo (forma de gota arredondada)
        const w = size * 0.5, h = size * 0.6;
        const x = cx - w / 2, y = cy - h / 2;
        ctx.beginPath();
        ctx.moveTo(cx, y);
        ctx.lineTo(x, y + h * 0.25);
        ctx.lineTo(x, y + h * 0.6);
        ctx.quadraticCurveTo(cx, y + h * 1.1, x + w, y + h * 0.6);
        ctx.lineTo(x + w, y + h * 0.25);
        ctx.closePath();
        ctx.fill();
        // Marca de checkmark
        ctx.strokeStyle = color;
        ctx.lineWidth = size * 0.09;
        ctx.beginPath();
        ctx.moveTo(cx - w * 0.22, cy);
        ctx.lineTo(cx - w * 0.04, cy + h * 0.18);
        ctx.lineTo(cx + w * 0.28, cy - h * 0.15);
        ctx.stroke();
        break;
      }
      case 'repair': {
        // Cruz vermelha
        const t = size * 0.18, l = size * 0.6;
        ctx.fillRect(cx - t / 2, cy - l / 2, t, l);
        ctx.fillRect(cx - l / 2, cy - t / 2, l, t);
        break;
      }
      case 'bomb': {
        // Esfera escura com pavio e brilho
        ctx.fillStyle = '#1a1a22';
        ctx.beginPath();
        ctx.arc(cx, cy + size * 0.06, size * 0.28, 0, Math.PI * 2);
        ctx.fill();
        // Reflexo
        ctx.fillStyle = '#ffffff';
        ctx.globalAlpha = 0.6;
        ctx.beginPath();
        ctx.arc(cx - size * 0.1, cy - size * 0.04, size * 0.06, 0, Math.PI * 2);
        ctx.fill();
        ctx.globalAlpha = 1;
        // Pavio
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = size * 0.06;
        ctx.beginPath();
        ctx.moveTo(cx + size * 0.16, cy - size * 0.18);
        ctx.quadraticCurveTo(cx + size * 0.28, cy - size * 0.32, cx + size * 0.18, cy - size * 0.38);
        ctx.stroke();
        // Faísca (pisca)
        const blink = (Math.sin(nowMs * 0.02) + 1) / 2;
        ctx.fillStyle = `rgba(255, 200, 80, ${0.5 + blink * 0.5})`;
        ctx.beginPath();
        ctx.arc(cx + size * 0.18, cy - size * 0.40, size * 0.05 + blink * 0.02 * size, 0, Math.PI * 2);
        ctx.fill();
        break;
      }
      case 'triple': {
        // Três setas/tiros pra cima em leque
        const drawArrow = (offsetX, angle) => {
          ctx.save();
          ctx.translate(cx + offsetX, cy);
          ctx.rotate(angle);
          ctx.beginPath();
          ctx.moveTo(0, -size * 0.26);
          ctx.lineTo(-size * 0.08, size * 0.16);
          ctx.lineTo(0, size * 0.06);
          ctx.lineTo(size * 0.08, size * 0.16);
          ctx.closePath();
          ctx.fill();
          ctx.restore();
        };
        drawArrow(-size * 0.18, -0.35);
        drawArrow(0, 0);
        drawArrow(size * 0.18, 0.35);
        break;
      }
      case 'clock': {
        // Relógio (ampulheta simples + ponteiros)
        ctx.lineWidth = size * 0.08;
        ctx.strokeStyle = '#ffffff';
        // Aro interno (já tem o externo na cápsula)
        ctx.beginPath();
        ctx.arc(cx, cy, size * 0.28, 0, Math.PI * 2);
        ctx.stroke();
        // Ponteiros (animados, mas devagar)
        const a = nowMs * 0.001;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(cx + Math.cos(a) * size * 0.16, cy + Math.sin(a) * size * 0.16);
        ctx.stroke();
        ctx.lineWidth = size * 0.06;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(cx + Math.cos(a * 0.2 - Math.PI / 2) * size * 0.22, cy + Math.sin(a * 0.2 - Math.PI / 2) * size * 0.22);
        ctx.stroke();
        // Ponto central
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(cx, cy, size * 0.04, 0, Math.PI * 2);
        ctx.fill();
        break;
      }
    }
  }

  function drawPowerups(nowMs) {
    for (const p of powerups) {
      const def = POWERUPS[p.type];
      const cx = p.x + p.w / 2;
      const cy = p.y + p.h / 2;
      const pulse = 1 + Math.sin(nowMs * 0.006) * 0.05;
      const size = p.w * pulse;

      // Halo
      const grad = ctx.createRadialGradient(cx, cy, 4, cx, cy, p.w * 0.9);
      grad.addColorStop(0, def.color + 'cc');
      grad.addColorStop(1, def.color + '00');
      ctx.fillStyle = grad;
      ctx.fillRect(p.x - 8, p.y - 8, p.w + 16, p.h + 16);

      drawPowerupIcon(ctx, def.icon, cx, cy, size, def.color, nowMs);
    }
  }

  // Expor pro debug HTML pré-renderizar cada ícone em mini-canvas.
  global.CampaignDrawPowerupIcon = drawPowerupIcon;

  function drawBossHud(nowMs) {
    if (!boss) return;
    const padX = 16, y = 16, barW = canvas.width - padX * 2, barH = 14;
    // Fundo
    ctx.fillStyle = 'rgba(0,0,0,0.65)';
    ctx.fillRect(padX, y, barW, barH);
    // Preenchimento por fase
    const pct = Math.max(0, boss.hp / boss.hpMax);
    const phase = currentBossPhase();
    const color = phase >= 3 ? '#ff3322' : phase >= 2 ? '#ff8c20' : '#ffd166';
    ctx.fillStyle = color;
    ctx.fillRect(padX, y, barW * pct, barH);
    // Marcadores das fases (50% e 25%)
    ctx.strokeStyle = 'rgba(255,255,255,0.55)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(padX + barW * 0.5, y); ctx.lineTo(padX + barW * 0.5, y + barH);
    ctx.moveTo(padX + barW * 0.25, y); ctx.lineTo(padX + barW * 0.25, y + barH);
    ctx.stroke();
    // Borda
    ctx.strokeStyle = 'rgba(255,255,255,0.5)';
    ctx.strokeRect(padX, y, barW, barH);
    // Texto
    ctx.fillStyle = '#fff';
    ctx.font = '700 12px ui-monospace,Menlo,monospace';
    ctx.textBaseline = 'top';
    ctx.fillText('BOSS — fase ' + (phase || 1), padX, y + barH + 4);
    ctx.textAlign = 'right';
    ctx.fillText(Math.ceil(boss.hp) + ' / ' + boss.hpMax, padX + barW, y + barH + 4);
    ctx.textAlign = 'left';
  }

  function drawBossWarning(nowMs) {
    if (!bossSpec || boss) return;
    if (bossWarningUntil === 0 || nowMs >= bossWarningUntil) return;
    // Pulse
    const pulse = 0.5 + 0.5 * Math.sin(nowMs * 0.012);
    ctx.fillStyle = 'rgba(255,40,40,' + (0.18 + pulse * 0.08) + ')';
    ctx.fillRect(0, canvas.height / 2 - 60, canvas.width, 120);
    ctx.fillStyle = '#ff4040';
    ctx.font = '900 32px ui-monospace,Menlo,monospace';
    ctx.textAlign = 'center';
    ctx.fillText('⚠ WARNING ⚠', canvas.width / 2, canvas.height / 2 - 6);
    ctx.fillStyle = '#fff';
    ctx.font = '600 14px ui-monospace,Menlo,monospace';
    ctx.fillText('BOSS APPROACHING', canvas.width / 2, canvas.height / 2 + 22);
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
  }

  function drawActiveEffectsHud(nowMs) {
    const x0 = canvas.width - 16;
    let y = 70;
    const drawBadge = (label, color, secsLeft) => {
      ctx.font = '600 11px ui-monospace,Menlo,monospace';
      const text = secsLeft != null ? `${label} ${secsLeft.toFixed(1)}s` : label;
      const w = ctx.measureText(text).width + 14;
      ctx.fillStyle = 'rgba(0,0,0,0.55)';
      ctx.fillRect(x0 - w, y, w, 18);
      ctx.fillStyle = color;
      ctx.fillRect(x0 - w, y, 3, 18);
      ctx.fillStyle = '#fff';
      ctx.textAlign = 'right';
      ctx.fillText(text, x0 - 6, y + 13);
      ctx.textAlign = 'left';
      y += 22;
    };
    if (effects.shield) drawBadge('SHIELD', POWERUPS.shield.color, null);
    if (isTripleShotActive(nowMs)) drawBadge('3X', POWERUPS.triple_shot.color, (effects.tripleShotUntil - nowMs) / 1000);
    if (isSlowTimeActive(nowMs))   drawBadge('SLOW', POWERUPS.slow_time.color, (effects.slowTimeUntil - nowMs) / 1000);
  }

  function drawParticles() {
    for (const p of particles) {
      const alpha = Math.max(0, Math.min(1, p.life / 28));
      ctx.fillStyle = p.color;
      ctx.globalAlpha = alpha;
      ctx.fillRect(p.x - p.size / 2, p.y - p.size / 2, p.size, p.size);
    }
    ctx.globalAlpha = 1;
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
    try {
      runFrame(ts);
    } catch (err) {
      console.error('[CampaignEngine] erro no frame:', err);
      // Re-agenda mesmo após erro pra não congelar tudo (próximos frames podem recuperar)
      rafId = requestAnimationFrame(loop);
    }
  }

  function runFrame(ts) {
    if (paused) {
      // Mantém o frame congelado e re-agenda; sem update, sem dt.
      lastFrameMs = ts;
      rafId = requestAnimationFrame(loop);
      return;
    }
    const dtMsRaw = Math.min(50, ts - (lastFrameMs || ts));
    lastFrameMs = ts;
    // Slow-motion durante morte do boss (1.2s a 30% da velocidade)
    const slowmoActive = ts < bossDeathSlowmoUntilMs;
    const dtMs = slowmoActive ? dtMsRaw * 0.3 : dtMsRaw;
    const dt = dtMs / 16.67;   // normaliza para "1 = um frame de 60fps"

    const elapsedMs = ts - startTimeMs - pauseOffsetMs;
    const elapsedSec = Math.floor(elapsedMs / 1000);
    const duration = stage && stage.duration_seconds ? stage.duration_seconds : 60;
    const timeLeft = Math.max(0, duration - elapsedSec);

    updateParallax(dt);
    updatePlayer(dt);
    autoShoot(ts);
    updateBullets(dt);
    tickWaveSpawn(ts);
    updateEnemies(dt, ts);
    updateEnemyBullets(dt);
    updatePowerups(dt);
    updateParticles(dt);
    handleCollisions();
    handlePowerupPickup(ts);
    maybeFireEndOfStageDrop();
    // Boss
    maybeStartBoss(ts);
    trySpawnBoss(ts);
    updateBoss(dt, ts);
    handleBossCollisions();
    handleBossMinionCollisions();

    // Screen-shake: translate o canvas todo enquanto o shake estiver ativo
    let shakeX = 0, shakeY = 0;
    if (ts < shakeUntilMs && shakeIntensity > 0) {
      // Decai com o tempo
      const remaining = (shakeUntilMs - ts) / 200;  // 200ms = pico
      const k = Math.min(1, remaining) * shakeIntensity;
      shakeX = (Math.random() - 0.5) * 2 * k;
      shakeY = (Math.random() - 0.5) * 2 * k;
    } else {
      shakeIntensity = 0;
    }

    ctx.save();
    if (shakeX || shakeY) ctx.translate(shakeX, shakeY);

    clear();
    drawTiledBackground(bgFar,  bgFarY);
    drawTiledBackground(bgMid,  bgMidY);
    drawTiledBackground(bgNear, bgNearY);
    drawEnemies();
    drawBoss();
    drawBossMinions();
    drawBossLaser(ts);
    drawBullets();
    drawPowerups(ts);
    drawParticles();
    drawPlayer();

    ctx.restore();

    // HUD fora do shake (UI nunca treme)
    drawHud(timeLeft);
    drawActiveEffectsHud(ts);
    drawBossHud(ts);
    drawBossWarning(ts);

    // Damage flash (vermelho semi-transparente sobre toda a tela)
    if (ts < damageFlashUntilMs) {
      const alpha = (damageFlashUntilMs - ts) / 220;
      ctx.fillStyle = 'rgba(255, 40, 40, ' + (alpha * 0.35) + ')';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    // Boss death flash (branco intenso)
    if (ts < bossDeathFlashUntilMs) {
      const alpha = (bossDeathFlashUntilMs - ts) / 400;
      ctx.fillStyle = 'rgba(255, 255, 255, ' + (alpha * 0.7) + ')';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    // Condições de fim
    if (player.hp <= 0) { endRun('loss'); return; }

    const allWavesDone = activeWave === null;  // advanceWave devolveu null
    const bossPending  = !!bossSpec && !bossEndedFired;
    if (allWavesDone && !bossPending &&
        enemies.length === 0 && enemyBullets.length === 0 && powerups.length === 0) {
      endRun('win');
      return;
    }
    if (bossEndedFired && enemies.length === 0 && enemyBullets.length === 0 && powerups.length === 0) {
      endRun('win');
      return;
    }
    if (timeLeft <= 0 && elapsedSec > duration + 8) {
      // Hard timeout (não deveria acontecer em fase normal)
      endRun(player.hp > 0 && enemiesDestroyed >= Math.ceil(plannedTotal * 0.7) ? 'win' : 'loss');
      return;
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
    enemyBullets.length = 0;
    enemies.length = 0;
    particles.length = 0;
    powerups.length = 0;
    enemiesDestroyed = 0;
    maxCombo = 0;
    combo = 0;
    damageTaken = 0;
    bgFarY = bgMidY = bgNearY = 0;
    endedFired = false;
    endGuaranteedDropFired = false;
    effects.shield = false;
    effects.tripleShotUntil = 0;
    effects.slowTimeUntil = 0;
    shakeUntilMs = 0;
    shakeIntensity = 0;
    damageFlashUntilMs = 0;
    bossDeathFlashUntilMs = 0;
    bossDeathSlowmoUntilMs = 0;

    spawnPlayer();
    loadBackgrounds(stage.sector);
    loadWaves(stage, opts.totalEnemies);
    bindInput();

    // Sprite da nave: opts.shipSpriteKey sobrescreve cfg
    if (opts.shipSpriteKey) {
      const img = global.CampaignAssets && global.CampaignAssets.tryGet(opts.shipSpriteKey);
      if (img) player.sprite = img;
    }

    // Aplicação de boosters (vindo do servidor via campaign-start.php)
    if (opts.booster === 'triple_star') {
      effects.shield = true;
    }

    running = true;
    paused = false;
    pauseOffsetMs = 0;
    pausedAtMs = 0;
    startTimeMs = performance.now();
    lastFrameMs = 0;
    waveStartMs = startTimeMs + 1500;  // 1.5s de "introdução" antes do primeiro spawn
    rafId = requestAnimationFrame(loop);
  }

  function pause() {
    if (!running || paused) return;
    paused = true;
    pausedAtMs = performance.now();
  }

  function resume() {
    if (!running || !paused) return;
    paused = false;
    const now = performance.now();
    pauseOffsetMs += now - pausedAtMs;
    // Desloca todos os timers de spawn baseados em wall-clock pra que a
    // pausa não conte como tempo decorrido.
    waveStartMs += now - pausedAtMs;
    if (boss) {
      const d = now - pausedAtMs;
      if (boss.lastFireMs)         boss.lastFireMs += d;
      if (boss.lastChargeMs)       boss.lastChargeMs += d;
      if (boss.lastShooterSpawnMs) boss.lastShooterSpawnMs += d;
      if (boss.laserStartMs)       boss.laserStartMs += d;
      if (boss.birthMs)            boss.birthMs += d;
    }
    if (bossWarningUntil > 0)      bossWarningUntil += now - pausedAtMs;
    pausedAtMs = 0;
    lastFrameMs = 0;
  }

  function isPaused() { return paused; }

  /**
   * Continua a sessão atual após game over: restaura HP, limpa
   * inimigos próximos, ativa shield brevemente. NÃO restaura HP do
   * boss (decisão da spec — boss continua de onde parou).
   */
  function revive() {
    if (!player) return;
    player.hp = cfg.shipMaxHp;
    damageTaken = 0;
    combo = 0;
    // Limpa inimigos perto do player para não morrer instantaneamente
    for (let i = enemies.length - 1; i >= 0; i--) {
      const en = enemies[i];
      if (Math.abs(en.y - player.y) < 200) {
        spawnExplosion(en.x + en.w / 2, en.y + en.h / 2, '#5cd5ff');
        enemies.splice(i, 1);
      }
    }
    enemyBullets.length = 0;
    // Escudo de cortesia
    effects.shield = true;
    endedFired = false;
    // Reativa o loop se foi cancelado
    if (!running) {
      running = true;
      paused = false;
      pausedAtMs = 0;
      lastFrameMs = 0;
      rafId = requestAnimationFrame(loop);
    }
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

  global.CampaignEngine = { start, stop, pause, resume, isPaused, revive };
})(window);
