/* ============================================
   UNOBIX - Campaign Asset Loader
   File: js/campaign-asset-loader.js

   Sistema agnóstico de carregamento de sprites do
   Modo Campanha. Aceita PNG e SVG transparentemente
   (ambos via HTMLImageElement) e expõe o mesmo
   contrato para o renderer. Permite trocar qualquer
   asset por outro formato sem refator no engine.

   Uso típico:
     CampaignAssets.preload(['ship_default','tank_rocky'])
       .then(() => requestAnimationFrame(gameLoop));
     const img = CampaignAssets.get('ship_default');
     ctx.drawImage(img, x, y, w, h);

   Listagem por categoria:
     CampaignAssets.byBehavior(1, 'kamikaze')
   ============================================ */

(function (global) {
  'use strict';

  const BASE = '/img/campaign';

  // Manifesto único: mantém alinhado com img/campaign/SPRITES.md
  // Cada chave é a sprite_key persistida no banco / usada no engine.
  const MANIFEST = {
    // ----- Naves do jogador (Kenney) -----
    ship_default:        `${BASE}/ships/ship-default.png`,
    ship_falcon_red:     `${BASE}/ships/ship-falcon-red.png`,
    ship_phantom_purple: `${BASE}/ships/ship-phantom-purple.png`,
    ship_golden_wing:    `${BASE}/ships/ship-golden-wing.png`,

    // ----- Setor 1 - Tank (asteroides marrom + cinza, vários tamanhos) -----
    s1_tank_asteroid_brown_1:  `${BASE}/asteroids/asteroid-brown-big-1.png`,
    s1_tank_asteroid_brown_2:  `${BASE}/asteroids/asteroid-brown-big-2.png`,
    s1_tank_asteroid_brown_3:  `${BASE}/asteroids/asteroid-brown-big-3.png`,
    s1_tank_asteroid_brown_4:  `${BASE}/asteroids/asteroid-brown-big-4.png`,
    s1_tank_asteroid_brown_m:  `${BASE}/asteroids/asteroid-brown-med-1.png`,
    s1_tank_asteroid_grey_1:   `${BASE}/asteroids/asteroid-grey-big-1.png`,
    s1_tank_asteroid_grey_2:   `${BASE}/asteroids/asteroid-grey-big-2.png`,
    s1_tank_asteroid_grey_3:   `${BASE}/asteroids/asteroid-grey-big-3.png`,
    s1_tank_asteroid_grey_4:   `${BASE}/asteroids/asteroid-grey-big-4.png`,

    // ----- Setor 1 - Bullet -----
    s1_bullet_drone_explorer: `${BASE}/enemies/sector1/bullet-drone-explorer.png`,
    s1_bullet_micro_rock:     `${BASE}/enemies/sector1/bullet-micro-rock.png`,
    s1_bullet_crystal_shard:  `${BASE}/enemies/sector1/bullet-crystal-shard.png`,

    // ----- Setor 1 - Kamikaze -----
    s1_kamikaze_drone:              `${BASE}/enemies/sector1/kamikaze-drone.png`,
    s1_kamikaze_volatile_fragment:  `${BASE}/enemies/sector1/kamikaze-volatile-fragment.png`,

    // ----- Setor 1 - Atirador -----
    s1_shooter_drone_laser:      `${BASE}/enemies/sector1/shooter-drone-laser.png`,
    s1_shooter_floating_turret:  `${BASE}/enemies/sector1/shooter-floating-turret.png`,

    // ----- Setor 1 - Esquivo -----
    s1_dodger_drone_digger: `${BASE}/enemies/sector1/dodger-drone-digger.png`,
    s1_dodger_alien_small:  `${BASE}/enemies/sector1/dodger-alien-small.png`,

    // ----- Setor 2 - Tank -----
    s2_tank_junk_giant: `${BASE}/enemies/sector2/tank-junk-giant.png`,

    // ----- Setor 2 - Bullet -----
    s2_bullet_pirate_drone: `${BASE}/enemies/sector2/bullet-pirate-drone.png`,
    s2_bullet_mini_ship:    `${BASE}/enemies/sector2/bullet-mini-ship.png`,

    // ----- Setor 2 - Kamikaze -----
    s2_kamikaze_alien:         `${BASE}/enemies/sector2/kamikaze-alien.png`,
    s2_kamikaze_suicide_drone: `${BASE}/enemies/sector2/kamikaze-suicide-drone.png`,

    // ----- Setor 2 - Atirador -----
    s2_shooter_pirate_ship:  `${BASE}/enemies/sector2/shooter-pirate-ship.png`,
    s2_shooter_alien_gunner: `${BASE}/enemies/sector2/shooter-alien-gunner.png`,

    // ----- Setor 2 - Esquivo -----
    s2_dodger_fast_ship:    `${BASE}/enemies/sector2/dodger-fast-ship.png`,
    s2_dodger_cyborg_alien: `${BASE}/enemies/sector2/dodger-cyborg-alien.png`,

    // ----- Bosses -----
    boss_asteroid_mother: `${BASE}/bosses/boss-asteroid-mother.png`,
    boss_junk_devourer:   `${BASE}/bosses/boss-junk-devourer.png`,

    // ----- Power-ups -----
    powerup_shield:      `${BASE}/powerups/powerup-shield.svg`,
    powerup_triple_shot: `${BASE}/powerups/powerup-triple-shot.svg`,
    powerup_repair:      `${BASE}/powerups/powerup-repair.svg`,
    powerup_bomb:        `${BASE}/powerups/powerup-bomb.svg`,
    powerup_slow_time:   `${BASE}/powerups/powerup-slow-time.svg`,

    // ----- Projéteis (Kenney) -----
    player_bullet:  `${BASE}/projectiles/player-bullet.png`,
    enemy_bullet:   `${BASE}/projectiles/enemy-bullet.png`,
    bullets_sheet:  `${BASE}/projectiles/bullets.png`,
    muzzle_flash:   `${BASE}/projectiles/muzzle-flash.png`,

    // ----- Backgrounds (parallax) -----
    bg_s1_far:   `${BASE}/backgrounds/sector1-far-stars.svg`,
    bg_s1_mid:   `${BASE}/backgrounds/sector1-mid-nebula.svg`,
    bg_s1_near:  `${BASE}/backgrounds/sector1-near-rocks.svg`,
    bg_s2_far:   `${BASE}/backgrounds/sector2-far-stars.svg`,
    bg_s2_mid:   `${BASE}/backgrounds/sector2-mid-nebula.svg`,
    bg_s2_near:  `${BASE}/backgrounds/sector2-near-debris.svg`,
  };

  // Mapas auxiliares para spawn aleatório por setor + behavior
  const BEHAVIOR_INDEX = {
    1: {
      tank:     [
        's1_tank_asteroid_brown_1','s1_tank_asteroid_brown_2',
        's1_tank_asteroid_brown_3','s1_tank_asteroid_brown_4',
        's1_tank_asteroid_brown_m',
        's1_tank_asteroid_grey_1','s1_tank_asteroid_grey_2',
        's1_tank_asteroid_grey_3','s1_tank_asteroid_grey_4',
      ],
      bullet:   ['s1_bullet_drone_explorer', 's1_bullet_micro_rock', 's1_bullet_crystal_shard'],
      kamikaze: ['s1_kamikaze_drone', 's1_kamikaze_volatile_fragment'],
      shooter:  ['s1_shooter_drone_laser', 's1_shooter_floating_turret'],
      dodger:   ['s1_dodger_drone_digger', 's1_dodger_alien_small'],
      miniboss: ['s1_shooter_drone_laser'],
    },
    2: {
      tank:     ['s2_tank_junk_giant'],
      bullet:   ['s2_bullet_pirate_drone', 's2_bullet_mini_ship'],
      kamikaze: ['s2_kamikaze_alien', 's2_kamikaze_suicide_drone'],
      shooter:  ['s2_shooter_pirate_ship', 's2_shooter_alien_gunner'],
      dodger:   ['s2_dodger_fast_ship', 's2_dodger_cyborg_alien'],
      miniboss: ['s2_shooter_pirate_ship'],
    },
  };

  const BG_INDEX = {
    1: { far: 'bg_s1_far', mid: 'bg_s1_mid', near: 'bg_s1_near' },
    2: { far: 'bg_s2_far', mid: 'bg_s2_mid', near: 'bg_s2_near' },
  };

  const cache = Object.create(null);   // key -> HTMLImageElement
  const inflight = Object.create(null); // key -> Promise

  function loadOne(key) {
    if (cache[key]) return Promise.resolve(cache[key]);
    if (inflight[key]) return inflight[key];

    const url = MANIFEST[key];
    if (!url) {
      return Promise.reject(new Error(`CampaignAssets: chave desconhecida "${key}"`));
    }

    inflight[key] = new Promise((resolve, reject) => {
      const img = new Image();
      img.decoding = 'async';
      img.onload = () => {
        cache[key] = img;
        delete inflight[key];
        resolve(img);
      };
      img.onerror = () => {
        delete inflight[key];
        reject(new Error(`CampaignAssets: falha ao carregar "${key}" (${url})`));
      };
      img.src = url;
    });

    return inflight[key];
  }

  /**
   * Pré-carrega uma lista de chaves. Resolve com Map<key, Image>.
   * Falhas individuais não interrompem (logam e seguem).
   */
  function preload(keys, opts) {
    const onProgress = opts && opts.onProgress;
    const total = keys.length;
    let done = 0;

    return Promise.all(
      keys.map((k) =>
        loadOne(k)
          .catch((err) => {
            console.warn('[CampaignAssets]', err.message);
            return null;
          })
          .then((img) => {
            done += 1;
            if (onProgress) onProgress(done, total, k);
            return [k, img];
          })
      )
    ).then((entries) => new Map(entries));
  }

  /** Pré-carrega todos os assets do manifesto. Use com parcimônia (warmup inicial). */
  function preloadAll(opts) {
    return preload(Object.keys(MANIFEST), opts);
  }

  /** Pré-carrega só o necessário para uma fase específica. */
  function preloadForStage(sector, behaviors, extras) {
    const keys = new Set(['ship_default', 'player_bullet', 'enemy_bullet']);
    (extras || []).forEach((k) => keys.add(k));

    const bg = BG_INDEX[sector];
    if (bg) { keys.add(bg.far); keys.add(bg.mid); keys.add(bg.near); }

    const ix = BEHAVIOR_INDEX[sector] || {};
    (behaviors || ['tank', 'bullet', 'kamikaze', 'shooter', 'dodger']).forEach((b) => {
      (ix[b] || []).forEach((k) => keys.add(k));
    });

    return preload(Array.from(keys));
  }

  /** Retorna a Image já carregada (sincrono). Lança se ainda não pré-carregada. */
  function get(key) {
    const img = cache[key];
    if (!img) {
      throw new Error(`CampaignAssets: "${key}" não está pré-carregado. Chame preload() antes.`);
    }
    return img;
  }

  /** Retorna Image se já carregada, ou null. Útil para fallback gracioso. */
  function tryGet(key) {
    return cache[key] || null;
  }

  /** Lista todas as sprite_keys de um (sector, behavior). */
  function byBehavior(sector, behavior) {
    return ((BEHAVIOR_INDEX[sector] || {})[behavior] || []).slice();
  }

  /** Sorteia uma sprite_key aleatória de um (sector, behavior). */
  function pickRandom(sector, behavior, rng) {
    const list = byBehavior(sector, behavior);
    if (!list.length) return null;
    const r = (typeof rng === 'function' ? rng() : Math.random());
    return list[Math.floor(r * list.length)];
  }

  /** Retorna chaves dos backgrounds (3 camadas) de um setor. */
  function backgroundKeys(sector) {
    return BG_INDEX[sector] || null;
  }

  global.CampaignAssets = {
    BASE,
    MANIFEST,
    BEHAVIOR_INDEX,
    BG_INDEX,
    preload,
    preloadAll,
    preloadForStage,
    get,
    tryGet,
    byBehavior,
    pickRandom,
    backgroundKeys,
  };
})(window);
