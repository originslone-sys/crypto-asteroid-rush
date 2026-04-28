# Prompts para gerar arte exclusiva (Midjourney / DALL-E / Leonardo.ai)

> O pack atual usa **Kenney Space Shooter** (qualidade indie pro, CC0).
> Para subir um patamar — arte exclusiva, estilo coerente com a sua marca —
> use os prompts abaixo. Resultado esperado: nível Sky Force / Phoenix 2 /
> Geometry Wars (estúdio AAA mobile).

## Como usar

1. Abra **Midjourney** (Discord), **DALL-E 3** (ChatGPT/Bing), ou **Leonardo.ai** (free tier).
2. Cole o prompt da sprite que quer gerar.
3. Gere variações até gostar.
4. Faça download em PNG transparente.
5. Substitua o arquivo correspondente em `/img/campaign/...`. **Mantenha o nome igual** — o engine puxa pelo path.

## Estilo unificado (use no início de cada prompt)

> "**Top-down 2D space shooter sprite, side view aerial perspective, dark cosmic background removed, transparent PNG, AAA mobile game studio quality, similar art style to Sky Force Reloaded and Phoenix 2, cyan and electric green neon accents matching brand palette (#00E5CC, #00FF88, #B8FF00), detailed metallic surfaces with subtle panel lines and rivets, dramatic rim lighting, professional polished sprite asset, sharp clean edges, 256x256 resolution.**"

Cole isso antes do prompt específico de cada sprite.

---

## 1. Naves do jogador (4 skins)

### `ship-default.png` (Nave Padrão)
> [estilo unificado] +
> "Sleek silver-blue starfighter, balanced agile design with twin engines emitting cyan trails, glass cockpit, two side wings angled forward, neutral and confident look. Center symmetric. **Replace** `/img/campaign/ships/ship-default.png`."

### `ship-falcon-red.png` (Falcon Vermelho)
> [estilo unificado] +
> "Aggressive crimson red interceptor with pointed wings, sharp angular hull, predator-like aesthetic, twin red engine glows, intimidating top-down silhouette. Streamlined for speed. **Replace** `/img/campaign/ships/ship-falcon-red.png`."

### `ship-phantom-purple.png` (Phantom — vamos rebatizar como Verde Toxic)
> [estilo unificado] +
> "Stealth-style toxic green ship with V-shaped swept wings, biolumi green underglow, geometric futuristic, cybernetic vibe, asymmetric panels with slick reflections. **Replace** `/img/campaign/ships/ship-phantom-purple.png`."

### `ship-golden-wing.png` (Premium dourada)
> [estilo unificado] +
> "Premium golden elite fighter with wide swept wings, ornate edge details, luxury feel, twin golden engine plumes, regal yet mean, gold-trimmed armor with cyan core glow. **Replace** `/img/campaign/ships/ship-golden-wing.png`."

---

## 2. Inimigos — Setor 1 (Cinturão de Asteroides)

### Tank (3 variações de asteroide)
- `asteroids/asteroid-brown-1.png`: "Rough brown rocky asteroid with deep craters, irregular silhouette, dirty earth tones, top-down view"
- `asteroids/asteroid-grey-1.png`: "Grey ice-rock asteroid with crystalline shards embedded, cold blue highlights"
- `asteroids/asteroid-grey-2.png`: "Dark grey volcanic asteroid with glowing red cracks of magma"

### Bullet (3 variações — drones rápidos pequenos)
- `enemies/sector1/bullet-drone-explorer.png`: "Small reconnaissance drone, single red sensor eye, dark hull, top-down, mean look"
- `enemies/sector1/bullet-micro-rock.png`: "Tiny biomechanical organism, alien tech, with pulsing core"
- `enemies/sector1/bullet-crystal-shard.png`: "Crystalline alien projectile creature, sharp edges, glowing green core"

### Kamikaze (2 variações — agressivos que perseguem)
- `enemies/sector1/kamikaze-drone.png`: "Aggressive arrow-shaped kamikaze drone, red warning lights, dark angular hull, ready to slam"
- `enemies/sector1/kamikaze-volatile-fragment.png`: "Unstable explosive alien fragment with cracks of orange plasma, glowing core, ready to detonate"

### Shooter (2 variações — param e atiram)
- `enemies/sector1/shooter-drone-laser.png`: "Heavy turret-style enemy with rotating laser cannon, square armored hull, red optical sensor"
- `enemies/sector1/shooter-floating-turret.png`: "Floating defense turret with hover platform underneath, twin barrels, sci-fi sentinel"

### Dodger (2 variações — zigzag)
- `enemies/sector1/dodger-drone-digger.png`: "Agile drilling drone with rotating drill on top, evasive hull"
- `enemies/sector1/dodger-alien-small.png`: "Small alien biomech UFO, organic curves, jelly-like membrane"

---

## 3. Inimigos — Setor 2 (Zona de Detritos)

Mesmo briefing dos do Setor 1 mas com paleta **mais hostil** (laranja-avermelhado, sucata pirata):
> [estilo unificado] + "Pirate junk aesthetic, scrap metal armor, orange-red palette, more menacing, mismatched panels welded together, oxidized look"

Lista de paths em `SPRITES.md`. Substitua os PNGs respectivos com a variante de Setor 2.

---

## 4. Bosses (alta prioridade — visual atual genérico)

### `boss-asteroid-mother.png` (Setor 1, F5)
> [estilo unificado] +
> "MASSIVE space asteroid boss creature, top-down view, 3x bigger than normal enemies, ancient rocky alien organism with glowing energy veins running through cracks, central pulsing red eye in deep crater, surrounded by orbiting smaller rocks, intimidating boss silhouette, AAA quality. **512x512 resolution**. Transparent PNG."

### `boss-junk-devourer.png` (Setor 2, F10)
> [estilo unificado] +
> "GIGANTIC pirate junk warship boss, top-down view, scrap metal hull patched together with welded plates, two enormous front cannons, twin red glowing eyes in cockpit, massive twin engines emitting orange plumes, multiple side guns, dripping with menace, AAA mobile boss design. **640x512 resolution**. Transparent PNG."

---

## 5. Power-ups (substituir SVGs por arte tipo Sky Force)

> [estilo unificado] +
> "Game power-up pickup icon, hovering glowing crystal/orb, lens flare halo, AAA mobile UI quality, transparent background, 128x128"

- `powerups/powerup-shield.png`: "+ shield bubble icon, electric blue energy orb"
- `powerups/powerup-triple-shot.png`: "+ three diverging arrows in golden frame"
- `powerups/powerup-repair.png`: "+ red medical cross capsule with pulsing heart"
- `powerups/powerup-bomb.png`: "+ explosive bomb with sparkling red fuse"
- `powerups/powerup-slow-time.png`: "+ frozen pocket watch with blue ice crystals"

---

## 6. Projéteis (sprites pequenos)

- `projectiles/player-bullet.png` — "Slim cyan plasma bolt 16x40, glowing trail, top-down"
- `projectiles/enemy-bullet.png` — "Hot orange-red plasma bolt 16x40, glowing trail"

---

## 7. Backgrounds (camadas parallax)

Cada setor tem 3 camadas. Resolução **414x896** (mesma do canvas portrait).

### Setor 1 — Cinturão de Asteroides
- `bg-sector1-far.png`: "Distant deep cosmic stars, dark blue-black, sparse blue-white tiny stars"
- `bg-sector1-mid.png`: "Cyan and indigo nebula clouds, swirling gas, mid-distance, transparent overlay"
- `bg-sector1-near.png`: "Foreground asteroids slowly drifting, brown and grey rocks, parallax"

### Setor 2 — Zona de Detritos
- `bg-sector2-far.png`: "Distant red-orange cosmic horror nebula, ominous"
- `bg-sector2-mid.png`: "Wreckage clouds, broken ship debris, fire and smoke clouds, ominous"
- `bg-sector2-near.png`: "Floating large junk pieces, broken hulls, twisted metal, slow drift"

---

## Dicas finais

- **Midjourney**: termina o prompt com `--ar 1:1 --style raw --v 6` para qualidade máxima.
- **DALL-E 3**: peça explicitamente "transparent background PNG, no scene around it, sprite cut out".
- **Leonardo.ai**: use modelo "Albedo Base XL" ou "DreamShaper v7", aplique "Transparent Background" e "Pixel Perfect".
- Após gerar, passe por **remove.bg** se a transparência não saiu limpa.
- Salve sempre em PNG (não JPG — JPG não tem transparência).

## Como o engine usa os assets

O `js/campaign-asset-loader.js` mapeia `key → path`. Você só precisa **substituir o arquivo no path**; o engine carrega automaticamente. Não precisa mexer no código.

Se trocar a extensão (PNG → WEBP, ou nome diferente), atualize o manifest em `MANIFEST = { ... }` no topo do `campaign-asset-loader.js`.
