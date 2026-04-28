# Sprites do Modo Campanha

> Pack visual oficial: **Kenney Space Shooter** (CC0, gratuito para uso comercial), via mirror em `Yecats/SuperSpaceShooter` e `djotaku/laserdefender` no GitHub.
> Backgrounds e power-ups continuam SVG escritos à mão (são genéricos o suficiente).

## Convenções

- **PNG** (32–112px): naves, inimigos, asteroides, projéteis, bosses — todos do Kenney pack.
- **SVG**: backgrounds parallax e power-ups (5).
- Sistema de carregamento agnóstico via `js/campaign-asset-loader.js` — você pode trocar qualquer PNG por outro só substituindo o arquivo.

---

## 1. Naves do jogador (4 skins)

| `skin_key` (DB) | Arquivo | Custo |
|---|---|---|
| `default` | `ships/ship-default.png` | Grátis |
| `falcon_red` | `ships/ship-falcon-red.png` | 50 créditos |
| `phantom_purple` | `ships/ship-phantom-purple.png` (verde toxic) | 50 créditos |
| `golden_wing` | `ships/ship-golden-wing.png` (caça avançado) | 100 créditos |

## 2. Inimigos — Setor 1 (Cinturão de Asteroides)

| `behavior` | Arquivo | Origem |
|---|---|---|
| `tank` (asteroide rochoso) | `asteroids/asteroid-brown-1.png` | Kenney meteorBrown |
| `tank` (asteroide cinza 1) | `asteroids/asteroid-grey-1.png` | Kenney meteorGrey |
| `tank` (asteroide cinza 2) | `asteroids/asteroid-grey-2.png` | Kenney meteorGrey |
| `bullet` (drone explorer) | `enemies/sector1/bullet-drone-explorer.png` | Kenney enemySmall |
| `bullet` (micro rock) | `enemies/sector1/bullet-micro-rock.png` | Kenney enemyBlack4 |
| `bullet` (crystal shard) | `enemies/sector1/bullet-crystal-shard.png` | Kenney enemyGreen5 |
| `kamikaze` (drone) | `enemies/sector1/kamikaze-drone.png` | Kenney enemyBlack1 |
| `kamikaze` (volatile) | `enemies/sector1/kamikaze-volatile-fragment.png` | Kenney enemyRed1 |
| `shooter` (drone laser) | `enemies/sector1/shooter-drone-laser.png` | Kenney enemyBlack3 |
| `shooter` (turret) | `enemies/sector1/shooter-floating-turret.png` | Kenney enemyRed4 |
| `dodger` (digger) | `enemies/sector1/dodger-drone-digger.png` | Kenney enemyBlack2 |
| `dodger` (alien small) | `enemies/sector1/dodger-alien-small.png` | Kenney enemyGreen5 |

## 3. Inimigos — Setor 2 (Zona de Detritos)

Setor 2 reusa os mesmos sprites Kenney com renomeação semântica para variar a apresentação visual. Para arte exclusiva por setor, gere PNGs novos seguindo `PROMPTS.md`.

## 4. Bosses

| `boss_id` | Nome | Arquivo |
|---|---|---|
| 1 | Asteroide-Mãe | `bosses/boss-asteroid-mother.png` (Kenney "Big Boy") |
| 2 | Devorador de Sucata | `bosses/boss-junk-devourer.png` (Kenney "Loopy") |

> Engine escala bosses para 160x160 / 192x160. PNGs Kenney têm ~80x80, então sofrem upscale. Para qualidade real de boss, gerar arte dedicada via `PROMPTS.md`.

## 5. Power-ups

| `key` | Arquivo |
|---|---|
| `shield` | `powerups/powerup-shield.svg` |
| `triple_shot` | `powerups/powerup-triple-shot.svg` |
| `repair` | `powerups/powerup-repair.svg` |
| `bomb` | `powerups/powerup-bomb.svg` |
| `slow_time` | `powerups/powerup-slow-time.svg` |

## 6. Projéteis

| `key` | Arquivo |
|---|---|
| `player_bullet` | `projectiles/player-bullet.png` (Kenney laserBlue01) |
| `enemy_bullet` | `projectiles/enemy-bullet.png` (Kenney laserRed01) |

## 7. Backgrounds (parallax 3 camadas)

SVG escritos à mão (não substituídos). Configurável no admin se quiser trocar.

| Setor | Camada | Arquivo |
|---|---|---|
| 1 | Far | `backgrounds/sector1-far-stars.svg` |
| 1 | Mid | `backgrounds/sector1-mid-nebula.svg` |
| 1 | Near | `backgrounds/sector1-near-rocks.svg` |
| 2 | Far | `backgrounds/sector2-far-stars.svg` |
| 2 | Mid | `backgrounds/sector2-mid-nebula.svg` |
| 2 | Near | `backgrounds/sector2-near-debris.svg` |

## Crédito de licença

Os sprites de naves, inimigos, asteroides, projéteis e bosses são do **Kenney Space Shooter Redux / Extension** packs, distribuídos sob licença **CC0** (domínio público). Uso comercial permitido, sem necessidade de atribuição (mas recomendada).

Para arte exclusiva (ex: substituir bosses, ter sprites únicos por setor), veja `PROMPTS.md`.
