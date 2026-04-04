# Design do Modo PvP - Nave vs Nave

**Data:** 2026-04-04
**Status:** Analise / Planejamento
**Documento Relacionado:** [pvp-infrastructure-analysis.md](./pvp-infrastructure-analysis.md)

---

## 1. Visao Geral

Modo competitivo onde dois jogadores se enfrentam em tempo real na mesma arena.
Cada jogador controla uma nave e o objetivo e destruir o oponente ou ter mais vidas
quando o tempo acabar. Asteroides surgem como obstaculos ambientais que ameacam ambas as naves.

```
┌─────────────────────────────────────────┐
│          Nave do Oponente (topo)        │
│          ← move X e Y →                │
│          Atira pra BAIXO ↓             │
│                                         │
│     ☄        ☄           ☄             │
│        ☄          ☄                     │
│   Asteroides surgem de todos os lados   │
│        ☄          ☄                     │
│     ☄        ☄           ☄             │
│                                         │
│          Atira pra CIMA ↑              │
│          ← move X e Y →                │
│          Nave do Jogador (base)         │
└─────────────────────────────────────────┘
```

**Campo de visao:** Tela inteira. Ambos jogadores veem a arena completa,
incluindo a nave inimiga no lado oposto.

---

## 2. Regras Definidas

### 2.1 Duracao
- **180 segundos** (3 minutos), mesma duracao do single-player

### 2.2 Vidas
- **6 vidas** por jogador
- Perder todas = **derrota imediata**

### 2.3 Condicoes de Vitoria (em ordem de prioridade)
1. **Oponente perde todas as 6 vidas** → Vitoria imediata
2. **Timer acaba** → Ganha quem tem **mais vidas**
3. **Empate em vidas** → Ganha quem **destruiu mais asteroides**
4. **Empate total** → Empate (ambos recebem reembolso parcial ou nenhum premio)
5. **Jogador desconecta** → Derrota por W.O.

### 2.4 Sistema de Recompensas
- **Entry fee:** Ambos jogadores pagam X creditos para entrar na partida
- **Vencedor:** Recebe premio fixo em creditos (valor definido pela config)
- **Ranking PvP semanal:** Vitorias contam para ranking, premiacao semanal para top jogadores
- **Perdedor:** Perde o entry fee (nao recebe nada)
- **Empate:** Reembolso parcial ou sem premio (definir)

---

## 3. Mecanicas do Jogo

### 3.1 Nave (Ship)

#### Posicionamento
| Propriedade | Player 1 (Jogador) | Player 2 (Oponente) |
|---|---|---|
| **Posicao inicial Y** | `canvas.height - 120` (base) | `120` (topo) |
| **Posicao inicial X** | Centro da tela | Centro da tela |
| **Direcao dos tiros** | Para cima (`y -= speed`) | Para baixo (`y += speed`) |
| **Renderizacao** | Normal | Invertida verticalmente (180°) |

#### Movimento
| Propriedade | Valor | Notas |
|---|---|---|
| **Eixos** | X e Y | Naves se movem em ambos os eixos (diferente do single-player) |
| **Velocidade X** | 18 px/frame | Mesmo do single-player |
| **Velocidade Y** | 12-14 px/frame | Mais lento que X para manter controle |
| **Limite X** | 50 a `canvas.width - 50` | Margem lateral |
| **Limite Y (Player 1)** | `canvas.height * 0.5` a `canvas.height - 50` | Metade inferior |
| **Limite Y (Player 2)** | `50` a `canvas.height * 0.5` | Metade superior |

> **Nota:** Cada jogador fica restrito a sua metade da tela em Y.
> Isso evita que naves se sobreponham e mantem a dinamica de combate a distancia.

#### Invencibilidade pos-dano
- **60 frames** (~1 segundo) apos receber dano
- Visual: nave pisca (alterna opacidade a cada 4 frames)
- Durante invencibilidade: ignora colisoes com balas E asteroides

### 3.2 Balas (Bullets)

#### Regras fundamentais
| Regra | Detalhes |
|---|---|
| **Balas sao bloqueadas por asteroides** | Bala que acerta asteroide destroi o asteroide e a bala |
| **Balas colidem entre si** | Bala do Player 1 que colide com bala do Player 2: ambas sao destruidas |
| **Quantidade limitada** | Maximo de balas simultaneas na tela por jogador (evita spam) |

#### Configuracao
| Propriedade | Valor | Notas |
|---|---|---|
| **Fire rate** | 300-400ms | Mais lento que single-player (150ms) para evitar spam |
| **Velocidade** | 14-16 px/frame | Similar ao single-player |
| **Max balas na tela** | 4-6 por jogador | Limite simultaneo, forca precisao |
| **Direcao P1** | Para cima (`y -= speed`) | |
| **Direcao P2** | Para baixo (`y += speed`) | |
| **Cor P1** | Verde (laser verde) | Mesmo do single-player |
| **Cor P2** | Vermelho (laser vermelho) | Diferenciar visualmente |
| **Dano** | 1 vida por acerto | |

#### Logica de colisao das balas (ordem de prioridade)
```
Para cada bala do jogador:
  1. Verifica colisao com asteroide → destroi ambos
  2. Verifica colisao com bala inimiga → destroi ambas
  3. Verifica colisao com nave inimiga → destroi bala, -1 vida inimigo
  4. Remove se saiu da tela
```

> **Importancia do limite de balas:** Com balas bloqueando asteroides e
> colidindo entre si, limitar a quantidade forca o jogador a ser preciso.
> Sem limite, a tela encheria de balas criando uma "parede" que eliminaria
> a competitividade.

### 3.3 Asteroides

#### Papel no PvP
Asteroides sao **obstaculos ambientais** que ameacam ambas as naves.
Nao dao recompensa em BRL (diferente do single-player). Servem como:
- Escudos naturais (bloqueiam balas inimigas)
- Ameacas (colidem e tiram vida)
- Elemento tatico (jogador decide entre destruir ou desviar)

#### Spawn
| Propriedade | Valor | Notas |
|---|---|---|
| **Direcoes de spawn** | Topo, base, laterais, centro | Multiplas direcoes |
| **Movimento** | Vertical, horizontal, diagonal | Componentes X e Y |
| **Velocidade** | 1.0 - 3.0 px/frame | Similar ao single-player |
| **Spawn rate** | 600-800ms | Mais lento que single-player (menos asteroides) |
| **Max na tela** | 8-12 | Menos que single-player (foco no combate) |
| **Tipos** | 1-2 tipos | Simplificado (sem raridade/recompensa) |
| **Tamanho** | 25-50px | Variado para gameplay interessante |
| **Dano** | 1 vida ao colidir com qualquer nave | |

#### Padrao de spawn
```
     ←←← spawn topo →→→
         ↓  ↓  ↓
    ─→   ☄  ☄  ☄   ←─    spawn laterais
    ─→      ☄      ←─
    ─→   ☄  ☄  ☄   ←─
         ↑  ↑  ↑
     ←←← spawn base →→→
```

Asteroides spawnando de multiplas direcoes criam um ambiente dinamico
onde ambos jogadores precisam se preocupar com ameacas de todos os lados,
nao apenas do oponente.

### 3.4 Colisoes - Tabela Completa

| Objeto A | Objeto B | Colide? | Resultado |
|---|---|---|---|
| Bala P1 | Asteroide | SIM | Destroi ambos, cria explosao |
| Bala P2 | Asteroide | SIM | Destroi ambos, cria explosao |
| Bala P1 | Nave P2 | SIM | Destroi bala, -1 vida P2, invencibilidade |
| Bala P2 | Nave P1 | SIM | Destroi bala, -1 vida P1, invencibilidade |
| Bala P1 | Bala P2 | SIM | Destroi ambas, mini explosao |
| Asteroide | Nave P1 | SIM | Destroi asteroide, -1 vida P1, invencibilidade |
| Asteroide | Nave P2 | SIM | Destroi asteroide, -1 vida P2, invencibilidade |
| Asteroide | Asteroide | NAO | Passam um pelo outro |
| Bala P1 | Nave P1 | NAO | Tiros proprios nao causam dano |
| Bala P1 | Bala P1 | NAO | Tiros do mesmo jogador nao colidem |
| Nave P1 | Nave P2 | NAO | Naves restritas a metades opostas |
| Particula | Qualquer | NAO | Apenas visual |

### 3.5 Condicao de Vitoria - Fluxo

```
A cada tick do servidor:
  │
  ├─ P1.vidas <= 0?
  │   └─ SIM → P2 VENCE (vitoria por eliminacao)
  │
  ├─ P2.vidas <= 0?
  │   └─ SIM → P1 VENCE (vitoria por eliminacao)
  │
  ├─ Ambos.vidas <= 0 no mesmo tick?
  │   └─ SIM → EMPATE (destruicao mutua)
  │
  ├─ Timer <= 0?
  │   ├─ P1.vidas > P2.vidas → P1 VENCE
  │   ├─ P2.vidas > P1.vidas → P2 VENCE
  │   ├─ Vidas iguais + P1.asteroids > P2.asteroids → P1 VENCE
  │   ├─ Vidas iguais + P2.asteroids > P1.asteroids → P2 VENCE
  │   └─ Tudo igual → EMPATE
  │
  └─ Jogador desconectou?
      └─ SIM → Oponente VENCE (W.O.)
```

---

## 4. Arquitetura Tecnica (Client-Side)

### 4.1 Game Loop - Mudanca Fundamental

**Single-player (atual):** Client calcula tudo.
**PvP:** Servidor e autoridade. Client so renderiza.

#### Client PvP (a cada frame ~60fps)
```
Frame:
  1. Captura input local (teclas/touch pressionados)
  2. Envia input ao Game Server via WebSocket: { keys: {left, right, up, down, fire} }
  3. Recebe estado do servidor:
     {
       player: { x, y, lives, invincible },
       opponent: { x, y, lives, invincible },
       playerBullets: [ {x, y} ... ],
       opponentBullets: [ {x, y} ... ],
       asteroids: [ {x, y, size, rotation, vx, vy} ... ],
       particles: [ {x, y, color, life} ... ],
       score: { playerAsteroids, opponentAsteroids },
       timeLeft: number
     }
  4. Interpola posicoes para suavizar (client-side prediction)
  5. Renderiza:
     - Background (estrelas, nebula) ← reutiliza 100%
     - Asteroides ← reutiliza renderer, novas direcoes
     - Balas jogador (verde) + balas oponente (vermelho)
     - Nave jogador (base) + Nave oponente (topo, invertida)
     - Particulas/explosoes ← reutiliza 100%
     - HUD com info de ambos jogadores
  6. requestAnimationFrame()
```

#### Game Server PvP (a cada tick ~60Hz / 16ms)
```
Tick:
  1. Recebe inputs de ambos jogadores
  2. Atualiza posicao da nave P1 (baseado em input P1)
  3. Atualiza posicao da nave P2 (baseado em input P2)
  4. Processa disparos (fire) respeitando fire rate e max balas
  5. Atualiza posicao de todas as balas
  6. Spawna asteroides (se necessario)
  7. Atualiza posicao de todos os asteroides
  8. Verifica colisoes (ordem):
     a. Balas vs Asteroides
     b. Balas P1 vs Balas P2
     c. Balas P1 vs Nave P2
     d. Balas P2 vs Nave P1
     e. Asteroides vs Nave P1
     f. Asteroides vs Nave P2
  9. Remove objetos destruidos/fora da tela
  10. Cria particulas de explosao
  11. Verifica condicao de vitoria/derrota
  12. Envia estado atualizado a ambos jogadores
```

### 4.2 HUD do PvP

```
┌─────────────────────────────────────────────────┐
│ [OPONENTE]                         [TEMPO: 2:45]│
│ ♥♥♥♥♥♡  NomeOponente   ☄12                     │
│                                                  │
│              (area de jogo)                      │
│                                                  │
│ ♥♥♥♥♥♥  SeuNome        ☄8        [JOGADOR]     │
└─────────────────────────────────────────────────┘

Legenda:
♥ = vida cheia    ♡ = vida perdida
☄N = asteroides destruidos
```

**Elementos do HUD:**
| Elemento | Posicao | Info |
|---|---|---|
| Timer | Topo central | Contagem regressiva 3:00 → 0:00 |
| Vidas do jogador | Inferior esquerdo | 6 coracoes visuais |
| Vidas do oponente | Superior esquerdo | 6 coracoes visuais |
| Nome do jogador | Inferior | display_name do Firebase |
| Nome do oponente | Superior | display_name do Firebase |
| Asteroides destruidos (jogador) | Inferior | Contador |
| Asteroides destruidos (oponente) | Superior | Contador |

### 4.3 Telas e Fluxo de Navegacao

```
[Menu Principal]
    │
    ├─→ [Modo Solo] (jogo atual, sem mudancas)
    │
    └─→ [Modo PvP]
         │
         ├─→ [Lobby/Matchmaking]
         │    - "Buscando oponente..."
         │    - Spinner + tempo de espera
         │    - Botao "Cancelar"
         │    - Entry fee exibido: "Custo: X creditos"
         │
         ├─→ [Oponente Encontrado]
         │    - Mostra nome + avatar do oponente
         │    - Countdown 3... 2... 1... FIGHT!
         │    - Ambos carregam arena
         │
         ├─→ [Partida PvP]
         │    - Arena tela inteira
         │    - HUD com info de ambos
         │    - 180 segundos
         │
         └─→ [Resultado PvP]
              - VITORIA! ou DERROTA... ou EMPATE
              - Stats comparativos lado a lado
              - Creditos ganhos/perdidos
              - Botoes: "Jogar Novamente" / "Voltar ao Menu"
              - Posicao no ranking PvP
```

---

## 5. Mapeamento de Arquivos - O Que Muda

### 5.1 Arquivos que precisam de NOVA VERSAO PvP

| Arquivo Atual | Nova Versao PvP | O que muda |
|---|---|---|
| `js/game-engine.js` | `js/pvp/pvp-game-engine.js` | Fisica sai do client, vira renderizador de estado |
| `js/game-renderer.js` | `js/pvp/pvp-game-renderer.js` | Renderiza 2 naves, 2 sets de balas, HUD PvP |
| `js/game-main.js` | `js/pvp/pvp-game-main.js` | Input via WebSocket, nao aplicado localmente |
| `js/game-session-manager.js` | `js/pvp/pvp-session-manager.js` | Conecta ao Game Server PvP, nao ao PHP |
| `js/game-config.js` | `js/pvp/pvp-game-config.js` | Constantes PvP (vidas, fire rate, limites) |
| `game.html` | `pvp-game.html` | Arena PvP + HUD duplo + matchmaking |
| `pregame.html` | `pvp-lobby.html` | Tela de matchmaking "buscando oponente" |
| `postgame.html` | `pvp-result.html` | Resultado comparativo + ranking PvP |

### 5.2 Arquivos REUTILIZADOS sem mudanca

| Arquivo | Motivo |
|---|---|
| `js/ship-renderer.js` | Design visual das naves (adicionar funcao de inversao) |
| `js/game-anticheat.js` | Anti-cheat agora e server-side, mas client pode manter basico |
| Background/estrelas/nebula | Reutiliza 100% |
| Particle system (explosoes) | Reutiliza 100% |
| `api/config.php` (auth) | Reutiliza autenticacao |
| `api/auth-google.php` | Reutiliza login |
| Sistema financeiro | Reutiliza creditos/BRL |

### 5.3 Novos arquivos BACKEND (PHP)

| Arquivo | Funcao |
|---|---|
| `api/pvp-authorize.php` | Valida creditos, gera token JWT para Game Server |
| `api/pvp-validate-token.php` | Game Server chama para validar token do jogador |
| `api/pvp-result.php` | Recebe resultado, credita vencedor, registra sessao |
| `api/pvp-ranking.php` | Ranking PvP semanal |

### 5.4 Novos arquivos GAME SERVER (Node.js - Compute Engine)

| Arquivo | Funcao |
|---|---|
| `server/index.js` | Entry point, WebSocket server |
| `server/matchmaking.js` | Fila de matchmaking, emparelhamento |
| `server/game-room.js` | Sala de jogo PvP, game loop server-side |
| `server/physics.js` | Movimento, colisoes, spawn de asteroides |
| `server/player.js` | Estado do jogador (nave, vidas, balas) |
| `server/config.js` | Constantes PvP server-side |

---

## 6. Constantes PvP Propostas

```
PVP CONFIG:
├── Geral
│   ├── GAME_DURATION: 180 segundos
│   ├── LIVES: 6 por jogador
│   ├── ENTRY_FEE: X creditos (configuravel)
│   ├── WINNER_PRIZE: Y creditos (configuravel)
│   └── TICK_RATE: 60 Hz (16ms por tick)
│
├── Nave
│   ├── SHIP_SPEED_X: 18 px/frame
│   ├── SHIP_SPEED_Y: 12 px/frame
│   ├── SHIP_HITBOX: 25 px radius
│   ├── INVINCIBILITY_FRAMES: 60 (~1 segundo)
│   ├── P1_Y_MIN: canvas.height * 0.5
│   ├── P1_Y_MAX: canvas.height - 50
│   ├── P2_Y_MIN: 50
│   └── P2_Y_MAX: canvas.height * 0.5
│
├── Balas
│   ├── BULLET_SPEED: 14-16 px/frame
│   ├── FIRE_RATE: 300-400 ms
│   ├── MAX_BULLETS_PER_PLAYER: 4-6
│   ├── BULLET_HITBOX: 8 px
│   ├── BULLET_WIDTH: 4 px
│   └── BULLET_HEIGHT: 20 px
│
├── Asteroides
│   ├── SPAWN_INTERVAL: 600-800 ms
│   ├── MAX_ON_SCREEN: 8-12
│   ├── SPEED_MIN: 1.0 px/frame
│   ├── SPEED_MAX: 3.0 px/frame
│   ├── SIZE_MIN: 25 px
│   ├── SIZE_MAX: 50 px
│   └── DAMAGE: 1 vida
│
└── Matchmaking
    ├── MAX_WAIT_TIME: 60 segundos
    ├── COUNTDOWN_BEFORE_START: 3 segundos
    └── DISCONNECT_TIMEOUT: 10 segundos
```

---

## 7. Decisoes Tecnicas Chave

### 7.1 Server-Authoritative
O servidor e a unica autoridade sobre o estado do jogo. O client NUNCA
decide se um tiro acertou ou se uma colisao aconteceu. Isso previne
trapaças e garante fairness.

### 7.2 Client-Side Prediction
Para minimizar a sensacao de lag, o client pode:
- Mover a nave local imediatamente ao pressionar tecla
- Corrigir posicao quando receber estado do servidor
- Interpolar posicoes de objetos entre ticks do servidor

### 7.3 Separacao Total do Single-Player
O modo PvP e um jogo SEPARADO. Nao modifica nenhum arquivo do single-player.
Novos arquivos em `js/pvp/` e novas paginas HTML. O menu principal
oferece a escolha entre os dois modos.

### 7.4 Balas Limitadas = Competitividade
A decisao de limitar balas (4-6 simultaneas, 300-400ms fire rate) e
fundamental. Sem isso:
- Balas encheriam a tela criando "paredes"
- Asteroides seriam destruidos instantaneamente (sem obstaculos)
- Balas colidindo entre si criariam explosoes constantes
- O jogo viraria spam de tiros sem estrategia

Com limite, cada tiro importa. O jogador precisa mirar, temporizar,
e decidir entre atirar no oponente ou destruir asteroide que se aproxima.

### 7.5 Asteroides como Elemento Tatico
Asteroides bloqueiam balas. Isso cria situacoes taticas:
- Um asteroide entre voce e o oponente serve como escudo temporario
- Destruir asteroide abre caminho para acertar o inimigo
- Ignorar asteroide pode resultar em colisao
- O jogador precisa equilibrar: destruir ameacas vs atacar oponente
