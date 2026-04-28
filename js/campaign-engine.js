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
    powerupDropChance: 0.12,
    powerupFallSpeed: 1.6,
  };

  // ----------------------------------------------------------------------
  // Catálogo de comportamentos de inimigos
  // Cada behavior define stats + função update(en, ctx)
  // ----------------------------------------------------------------------
  const BEHAVIORS = {
    tank:     { hp: 3, damage: 25, w: 56, h: 56, baseSpeed: 1.2 },
    bullet:   { hp: 1, damage: 10, w: 44, h: 44, baseSpeed: 2.4 },
    kamikaze: { hp: 2, damage: 30, w: 50, h: 50, baseSpeed: 1.6 },
    shooter:  { hp: 4, damage: 15, w: 52, h: 52, baseSpeed: 1.0,
                stopY: 180, fireIntervalMs: 1500 },
    dodger:   { hp: 2, damage: 20, w: 48, h: 48, baseSpeed: 1.8,
                amplitude: 60, frequency: 0.05 },
  };

  // ----------------------------------------------------------------------
  // Catálogo de power-ups
  // - 'shield' / 'repair' / 'bomb': aplicação instantânea
  // - 'triple_shot' / 'slow_time': efeito com duração (ms)
  // ----------------------------------------------------------------------
  const POWERUPS = {
    shield:      { spriteKey: 'powerup_shield',      timed: false, color: '#5cd5ff' },
    repair:      { spriteKey: 'powerup_repair',      timed: false, color: '#ff5566', amount: 25 },
    bomb:        { spriteKey: 'powerup_bomb',        timed: false, color: '#ff7e4a' },
    triple_shot: { spriteKey: 'powerup_triple_shot', timed: true,  color: '#ffd166', durationMs: 10000 },
    slow_time:   { spriteKey: 'powerup_slow_time',   timed: true,  color: '#a0aaff', durationMs: 5000, slowFactor: 0.5 },
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
      damage: def.damage,
      sprite,
      // Campos por behavior
      stopY: def.stopY || null,
      stopped: false,
      lastFireMs: 0,
      fireIntervalMs: def.fireIntervalMs || 1500,
      // Dodger
      birthMs: performance.now(),
      amplitude: def.amplitude || 0,
      frequency: def.frequency || 0,
      anchorX: 0,  // setado no primeiro update
    });
  }

  function pickSpriteForBehavior(sector, behaviorKey) {
    if (!global.CampaignAssets) return null;
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
      if (en.y > canvas.height + 30) {
        enemies.splice(i, 1);
        combo = 0;  // escapou da tela: penaliza combo
      }
    }
  }

  function updateEnemyByBehavior(en, dt, nowMs) {
    switch (en.behavior) {
      case 'kamikaze': {
        en.y += en.vy * dt;
        // Persegue lateralmente quando entra na tela
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
      sprite: (global.CampaignAssets && global.CampaignAssets.tryGet(def.spriteKey)) || null,
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
    // Explosão grande
    for (let i = 0; i < 30; i++) {
      spawnExplosion(boss.x + boss.w / 2 + (Math.random() - 0.5) * boss.w,
                     boss.y + boss.h / 2 + (Math.random() - 0.5) * boss.h,
                     ['#ffd166','#ff7e4a','#ff3322'][Math.floor(Math.random() * 3)]);
    }
    // Drops garantidos
    if (bossSpec && bossSpec.drops) {
      bossSpec.drops.forEach((type, i) => {
        spawnPowerup(boss.x + boss.w / 2 + (i - bossSpec.drops.length / 2) * 60, boss.y + boss.h / 2, type);
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
          spawnExplosion(cx, cy, '#ff7e4a');
          maybeDropPowerup(cx, cy);
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
      return;
    }
    player.hp = Math.max(0, player.hp - amount);
    damageTaken += amount;
    combo = 0;
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

  function drawPowerups(nowMs) {
    for (const p of powerups) {
      // Pulsa levemente
      const pulse = 1 + Math.sin(nowMs * 0.006) * 0.05;
      const w = p.w * pulse, h = p.h * pulse;
      const x = p.x + p.w / 2 - w / 2;
      const y = p.y + p.h / 2 - h / 2;

      // Halo
      const grad = ctx.createRadialGradient(p.x + p.w / 2, p.y + p.h / 2, 4, p.x + p.w / 2, p.y + p.h / 2, p.w * 0.9);
      grad.addColorStop(0, POWERUPS[p.type].color + 'cc');
      grad.addColorStop(1, POWERUPS[p.type].color + '00');
      ctx.fillStyle = grad;
      ctx.fillRect(p.x - 8, p.y - 8, p.w + 16, p.h + 16);

      if (p.sprite && p.sprite.complete) {
        ctx.drawImage(p.sprite, x, y, w, h);
      } else {
        ctx.fillStyle = POWERUPS[p.type].color;
        ctx.fillRect(x, y, w, h);
      }
    }
  }

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
    if (paused) {
      // Mantém o frame congelado e re-agenda; sem update, sem dt.
      lastFrameMs = ts;
      rafId = requestAnimationFrame(loop);
      return;
    }
    const dtMs = Math.min(50, ts - (lastFrameMs || ts));
    lastFrameMs = ts;
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
    drawHud(timeLeft);
    drawActiveEffectsHud(ts);
    drawBossHud(ts);
    drawBossWarning(ts);

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
