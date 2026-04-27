# Sprites do Modo Campanha

> 42 assets organizados por categoria. Cada inimigo tem `behavior` (comportamento de IA) + `sprite` (visual). O **mesmo comportamento pode ter múltiplas skins** distribuídas entre fases para evitar repetição visual.

## Convenções

- **PNG**: arte rasterizada (asteroides + projéteis base) — vinda do `phaser3-examples` (CC-BY).
- **SVG**: arte vetorial exclusiva — desenhada para este projeto, escala perfeita, swap por PNG depois sem refator.
- **Resolução de design**:
  - Inimigos pequenos: viewBox `48x48`
  - Inimigos médios: viewBox `56x56`
  - Naves do jogador: viewBox `64x64`
  - Bosses: viewBox `160x160` (asteroide-mãe) e `192x160` (devorador)
  - Backgrounds: viewBox `414x896` (mesma proporção do canvas portrait)

---

## 1. Naves do jogador (4 skins)

| `skin_key` (DB) | Arquivo | Custo |
|---|---|---|
| `default` | `ships/ship-default.svg` | Grátis |
| `falcon_red` | `ships/ship-falcon-red.svg` | 50 créditos |
| `phantom_purple` | `ships/ship-phantom-purple.svg` | 50 créditos |
| `golden_wing` | `ships/ship-golden-wing.svg` | 100 créditos |

> A skin **lendária** (drop do boss final F10) ainda não tem arte; será adicionada em iteração futura. Sugestão de chave: `legendary_devourer`.

## 2. Inimigos — Setor 1 (Cinturão de Asteroides)

| `behavior` | Skin (sprite) | Arquivo | HP | Dano |
|---|---|---|---|---|
| `tank` | asteroid-rocky | `asteroids/asteroid1.png` | 3 | 25 |
| `tank` | asteroid-ice | `asteroids/asteroid2.png` | 3 | 25 |
| `tank` | asteroid-crystal | `asteroids/asteroid3.png` | 3 | 25 |
| `bullet` | drone-explorer | `enemies/sector1/bullet-drone-explorer.svg` | 1 | 10 |
| `bullet` | micro-rock | `enemies/sector1/bullet-micro-rock.svg` | 1 | 10 |
| `bullet` | crystal-shard | `enemies/sector1/bullet-crystal-shard.svg` | 1 | 10 |
| `kamikaze` | drone | `enemies/sector1/kamikaze-drone.svg` | 2 | 30 |
| `kamikaze` | volatile-fragment | `enemies/sector1/kamikaze-volatile-fragment.svg` | 2 | 30 |
| `shooter` | drone-laser | `enemies/sector1/shooter-drone-laser.svg` | 4 | 15 (projétil) |
| `shooter` | floating-turret | `enemies/sector1/shooter-floating-turret.svg` | 4 | 15 (projétil) |
| `dodger` | drone-digger | `enemies/sector1/dodger-drone-digger.svg` | 2 | 20 |
| `dodger` | alien-small | `enemies/sector1/dodger-alien-small.svg` | 2 | 20 |

## 3. Inimigos — Setor 2 (Zona de Detritos)

| `behavior` | Skin (sprite) | Arquivo | HP | Dano |
|---|---|---|---|---|
| `tank` | junk-giant | `enemies/sector2/tank-junk-giant.svg` | 3 | 25 |
| `bullet` | pirate-drone | `enemies/sector2/bullet-pirate-drone.svg` | 1 | 10 |
| `bullet` | mini-ship | `enemies/sector2/bullet-mini-ship.svg` | 1 | 10 |
| `kamikaze` | alien | `enemies/sector2/kamikaze-alien.svg` | 2 | 30 |
| `kamikaze` | suicide-drone | `enemies/sector2/kamikaze-suicide-drone.svg` | 2 | 30 |
| `shooter` | pirate-ship | `enemies/sector2/shooter-pirate-ship.svg` | 4 | 15 (projétil) |
| `shooter` | alien-gunner | `enemies/sector2/shooter-alien-gunner.svg` | 4 | 15 (projétil) |
| `dodger` | fast-ship | `enemies/sector2/dodger-fast-ship.svg` | 2 | 20 |
| `dodger` | cyborg-alien | `enemies/sector2/dodger-cyborg-alien.svg` | 2 | 20 |

> Mini-bosses do Setor 2 podem reusar `tank-junk-giant.svg` em escala maior + variação de cor até termos arte dedicada.

## 4. Bosses

| `boss_id` | Nome | Sector | Arquivo | HP | Tamanho |
|---|---|---|---|---|---|
| 1 | Asteroide-Mãe | 1 | `bosses/boss-asteroid-mother.svg` | 500 | 40% da tela |
| 2 | Devorador de Sucata | 2 | `bosses/boss-junk-devourer.svg` | 1000 | 60% da tela |

## 5. Power-ups

| `powerup_key` | Arquivo | Efeito |
|---|---|---|
| `shield` | `powerups/powerup-shield.svg` | Bloqueia próximo dano |
| `triple_shot` | `powerups/powerup-triple-shot.svg` | 3 projéteis em leque (10s) |
| `repair` | `powerups/powerup-repair.svg` | +25 HP instantâneo |
| `bomb` | `powerups/powerup-bomb.svg` | Destrói tudo na tela |
| `slow_time` | `powerups/powerup-slow-time.svg` | Inimigos -50% velocidade (5s) |

## 6. Projéteis

| `key` | Arquivo | Origem |
|---|---|---|
| `player_bullet` | `projectiles/player-bullet.svg` | Padrão do jogador |
| `enemy_bullet` | `projectiles/enemy-bullet.svg` | Padrão dos atiradores |
| `bullets` | `projectiles/bullets.png` | Spritesheet do phaser (fallback) |
| `muzzle_flash` | `projectiles/muzzle-flash.png` | Flash do tiro |

## 7. Backgrounds (parallax 3 camadas)

| Setor | Camada | Arquivo | Velocidade relativa sugerida |
|---|---|---|---|
| 1 | Far (estrelas) | `backgrounds/sector1-far-stars.svg` | 0.2x |
| 1 | Mid (nebulosa+planeta) | `backgrounds/sector1-mid-nebula.svg` | 0.5x |
| 1 | Near (rochas) | `backgrounds/sector1-near-rocks.svg` | 1.0x |
| 2 | Far (estrelas warm) | `backgrounds/sector2-far-stars.svg` | 0.2x |
| 2 | Mid (nebulosa vermelha) | `backgrounds/sector2-mid-nebula.svg` | 0.5x |
| 2 | Near (sucata) | `backgrounds/sector2-near-debris.svg` | 1.0x |

> Velocidades configuráveis no admin (campaign.scroll.layer_speed_*).

## Distribuição por fase (sugestão inicial)

Ondas devem misturar variações da mesma "behavior" para evitar repetição. Exemplo F1 (8 asteroides):
```
Onda 1: 4× asteroid-rocky + 2× asteroid-ice + 2× drone-explorer
Onda 2: 3× asteroid-crystal + 2× kamikaze-drone
Onda 3: 3× micro-rock
```

## Roadmap futuro de assets

- ❌ Skin lendária do boss final (`legendary_devourer.svg`)
- ❌ Mini-bosses dedicados por setor (sprites próprios em vez de reuso)
- ❌ Variações cromáticas por fase (mesmo sprite, paleta levemente diferente)
- ❌ Substituir SVGs por PNGs gerados por IA quando o jogador quiser arte mais "ilustrada"
