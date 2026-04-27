# Modo Campanha — Documento de Planejamento

> Status: **Em mapeamento** | Branch: `claude/new-game-mode-planning-crtrQ`

## Objetivo
Criar um novo modo de jogo inspirado em **Space Impact** (Nokia clássico), com fases sequenciais, sistema de XP, vidas e chefões. Foco em **engajamento**, **retenção** e **monetização**.

---

## 1. Escopo do Lançamento

| Item | Decisão |
|---|---|
| Fases | 10 fases + 1 fase treino |
| Setores | 2 setores (5 fases cada) |
| Bosses | 2 (Fase 5 e Fase 10) |
| HTML | Separado (`campaign.html`, `campaign-game.html`) |
| Plataforma | Somente web |
| Tela | Formato mobile (portrait) — mesmo no PC, em janela mobile centralizada |
| Vidas | Sistema de vidas |
| Recompensas | BRL |
| Custo de fase | Créditos (inclusive fase treino) |
| Desbloqueio | Por nível de XP (não sequencial) |
| Arte | Nova e exclusiva |
| Painel Admin | Página dedicada para gerenciamento completo |

---

## 2. Sistema de XP ✅

### Níveis
- **Total:** 30 níveis
- **XP fixo por fase** (não escala com dificuldade ou desempenho do jogador)
- **Equilibrado** entre fase treino e fases normais (treino dá menos, mas pode ser repetida)

### XP por fase (proposta)

| Fase | XP fixo | Observação |
|---|---|---|
| Treino | 50 | Repetível para farm controlado |
| F1 | 80 | |
| F2 | 100 | |
| F3 | 120 | |
| F4 | 140 | |
| **F5 (boss)** | 200 | Final do Setor 1 |
| F6 | 180 | |
| F7 | 200 | |
| F8 | 220 | |
| F9 | 240 | |
| **F10 (boss)** | 350 | Boss final |

### Tabela de desbloqueio

| Fase | Nível mínimo |
|---|---|
| Treino | 1 |
| F1 | 1 |
| F2 | 3 |
| F3 | 5 |
| F4 | 8 |
| **F5 (boss)** | 11 |
| F6 | 14 |
| F7 | 17 |
| F8 | 20 |
| F9 | 23 |
| **F10 (boss)** | 25 |

### Curva de XP por nível (cumulativo)

| Nível | XP total acumulado |
|---|---|
| 1 | 0 |
| 3 | 220 |
| 5 | 500 |
| 8 | 1.000 |
| 11 | 1.700 |
| 14 | 2.500 |
| 17 | 3.500 |
| 20 | 4.700 |
| 23 | 6.000 |
| 25 | 7.500 |
| 30 | 11.000 |

### Configurável no painel admin
- XP por fase (cada fase editável)
- Nível mínimo de desbloqueio por fase
- Tabela de XP por nível
- XP bônus por estrelas (a definir)

---

## 3. Sistema de Vidas ✅

### Configuração base
| Parâmetro | Valor |
|---|---|
| Vidas máximas | **5** |
| Tempo de recarga por vida | **30 minutos** |
| Tempo total para encher do zero | 2h30 |
| Quando consome | **Ao falhar a fase** (vencer não consome vida) |
| Escopo | **Exclusivas da campanha** (não interferem em outros modos) |

### Premium
- Recarga **2x mais rápida** (15 min por vida)
- Vidas máximas aumentadas para **7**

### Compra com créditos
| Opção | Custo |
|---|---|
| 1 vida avulsa | 1 crédito |
| Pacote de 5 vidas (com desconto) | 4 créditos |
| Refill completo (encher tudo) | 3 créditos |

> Refill completo só disponível quando vidas < máximo.

### Configurável no painel admin
- ✅ Quantidade máxima de vidas (padrão e premium)
- ✅ Tempo de recarga em minutos (padrão e premium)
- ✅ Comportamento de consumo (ao entrar / ao falhar)
- ✅ Custo de compra avulsa
- ✅ Custo do pacote de 5 vidas
- ✅ Custo do refill completo
- ✅ Toggle de "vidas ilimitadas" para promoções/eventos

## 4. Custo em Créditos ✅

### Modelo: cobrança **por setor** (com override por fase no admin)

| Fase | Custo (créditos) |
|---|---|
| Treino | 1 |
| F1 a F5 (Setor 1) | 1 |
| F6 a F10 (Setor 2) | 2 |

### Regras
- **Bosses (F5 e F10)** não cobram extra — custo igual ao do setor
- **Re-jogar fase** (para melhorar estrelas): mesmo custo da 1ª vez (protege economia)
- **Continuar após game over** (recupera 1 vida + ressuscita no ponto): **+2 créditos**

### Configurável no painel admin
- ✅ Custo padrão por setor
- ✅ Override de custo por fase individual (sobrepõe o do setor)
- ✅ Custo da fase treino
- ✅ Toggle: cobrar extra em bosses + valor extra
- ✅ Custo de re-jogada (% do original OU valor fixo)
- ✅ Custo do "continuar" após game over
- ✅ Toggle "fases grátis" (promoções/eventos)
- ✅ Toggle "primeira jogada grátis" por fase recém-desbloqueada

## 5. Recompensas em BRL e Estrelas ✅

### Recompensa base (1ª vez completando)

| Fase | BRL base (1⭐) | Observação |
|---|---|---|
| Treino | R$ 0,00 | Treino dá só XP |
| F1 | R$ 0,05 | |
| F2 | R$ 0,08 | |
| F3 | R$ 0,12 | |
| F4 | R$ 0,18 | |
| **F5 (boss)** | R$ 0,40 | Final do Setor 1 |
| F6 | R$ 0,25 | |
| F7 | R$ 0,35 | |
| F8 | R$ 0,50 | |
| F9 | R$ 0,70 | |
| **F10 (boss)** | R$ 1,50 | Boss final |

**Total possível 1ª vez (sem bônus de estrelas):** R$ 5,13
**Custo total em créditos para completar:** 16 créditos

### Multiplicador por estrelas
| Estrelas | Multiplicador |
|---|---|
| 1 ⭐ | 100% (base) |
| 2 ⭐ | 125% |
| 3 ⭐ | 150% |

### Critérios das estrelas
- **1 ⭐** Completar a fase (vencer)
- **2 ⭐** Completar perdendo no máximo **50% de HP**
- **3 ⭐** Completar **sem tomar dano** (perfect run)

### Política de re-jogada
- Paga apenas a **diferença** entre estrelas anteriores e novas
  - Ex: tinha 1⭐ (recebeu 100%), agora fez 3⭐ → recebe os **50% extras**
- Evita farm e incentiva o jogador a perseguir 3⭐

### Bônus de sequência (streak)
- Completar **3 fases seguidas sem falhar** → **+10%** na próxima fase
- Configurável (% e número de fases que ativam)

### Limite diário
- **R$ 10,00 por dia** em recompensas da campanha (anti-abuse)
- Toggle no admin para desabilitar

### Configurável no painel admin
- ✅ BRL base por fase (cada fase editável)
- ✅ Multiplicadores por estrelas (1⭐, 2⭐, 3⭐)
- ✅ Critério da 2⭐ (% de HP máximo perdido)
- ✅ Política de re-jogada (apenas diferença / 100% / % reduzida)
- ✅ Bônus de sequência (% e quantidade de fases)
- ✅ Limite diário de ganho (valor + toggle on/off)
- ✅ Evento "recompensa dobrada" (toggle global)
- ✅ Promoções temporárias por fase (multiplicador customizado + janela de tempo)

## 6. Resolução / Orientação Mobile ✅

### Orientação
- **Portrait (vertical)** — padrão mobile moderno
- Bloqueio de rotação ativo (se o usuário girar o celular, força volta para portrait)

> Caso o tipo de scroll definido na seção 7 seja side-scroller landscape, esta decisão será revista.

### Resolução do canvas
- **414 x 896 px** (referência iPhone 11/12/13 — boa área visível e compatibilidade ampla)

### Comportamento no Desktop
- Janela mobile **centralizada** (414x896)
- Background ao redor: **espelho do gameplay desfocado** (estilo Netflix) — imersivo e focal
- Sem barra de scroll do navegador interferindo

### Comportamento no Mobile (celular real)
- **Tela cheia** (fullscreen API) — UX nativa
- Esconde barra de URL e barra de status quando possível

### Controles

**Mobile (touch):**
- Arrastar com o dedo para mover a nave
- **Tiro automático**

**Desktop:**
- **WASD** ou **setas** para mover
- **Tiro automático** (mesma mecânica do mobile)
- Espaço reservado para futuros power-ups/tiro especial

### Configurável no painel admin
- ✅ Resolução do canvas (largura × altura)
- ✅ Orientação forçada (portrait / landscape)
- ✅ Bloqueio de rotação ON/OFF
- ✅ Tema do background lateral no desktop (gameplay-blur / cor sólida / imagem)
- ✅ Tiro automático ON/OFF (testar variantes)
- ✅ Sensibilidade do touch (multiplicador 0.5x a 2.0x)
- ✅ Velocidade base de movimento da nave

## 7. Tipo de Scroll ✅

### Decisão: **Vertical scroll com cenário rolando** (estilo Sky Force / Galaga / 1942)

### Como funciona
- Cenário rola **de cima para baixo** (parallax vertical contínuo)
- Inimigos e asteroides entram pela parte superior da tela
- Nave do jogador se move livremente dentro da área inferior da tela
- Ao chegar no boss, o **cenário trava** e a tela vira uma "arena" para a luta
- Mantém a essência do Space Impact (fases, ondas, bosses, power-ups) adaptada para portrait

### Justificativa
- Casa perfeitamente com a orientação **portrait** já definida
- Formato consagrado em mobile (Sky Force, Phoenix Force)
- Diferencia visualmente dos modos atuais (que não têm cenário rolando)
- Permite arte temática por setor sem complicar implementação

### Camadas de parallax (proposta inicial)
1. **Camada de fundo:** estrelas distantes (rolagem lenta)
2. **Camada do meio:** nebulosa / planetas (rolagem média)
3. **Camada de frente:** asteroides decorativos / detritos (rolagem rápida)

### Tema visual por setor
| Setor | Tema |
|---|---|
| Setor 1 | Cinturão de Asteroides (azul/cinza, rochas) |
| Setor 2 | Zona de Detritos (laranja/vermelho, sucata espacial) |

### Configurável no painel admin
- ✅ Velocidade do scroll do cenário (ajustável por fase)
- ✅ Número de camadas de parallax (1, 2 ou 3 — para performance)
- ✅ Tema visual por setor (asset pack: estrelas, asteroides, nebulosa, etc)
- ✅ Velocidade base dos inimigos descendo
- ✅ Tempo até boss aparecer (após cenário travar)
- ✅ Toggle: cenário continua rolando durante boss fight (sim/não)

## 8. Mecânicas de Gameplay ✅

### HP da nave
- **HP em barra** com **100 HP** padrão
- Permite balanceamento fino e casa com o critério de estrelas (2⭐ ≤ 50% dano, 3⭐ sem dano)

### Tiros
- **Tiro padrão:** automático, contínuo, dano baixo
- **Munição:** infinita (estilo arcade clássico)
- **Tiros especiais:** apenas via power-ups dropados

### Power-ups (5 no MVP)

| Power-up | Efeito | Duração |
|---|---|---|
| 🛡️ Escudo | Bloqueia o próximo dano | Até ser atingido |
| ⚡ Tiro Triplo | 3 projéteis em leque | 10s |
| ❤️ Reparo | Recupera 25 HP | Instantâneo |
| 💣 Bomba | Destrói todos os inimigos na tela | Instantâneo |
| ⏰ Slow-time | Inimigos 50% mais lentos | 5s |

> Power-ups para expansão futura: Mísseis, Multiplicador BRL.

### Sistema de drop de power-ups
- **Modelo híbrido:**
  - Power-ups **garantidos** em pontos-chave de algumas ondas (script)
  - Power-ups **aleatórios** em inimigos especiais raros (chance baixa)

### Tipos de inimigos (MVP — 6 tipos)

| Inimigo | Comportamento | HP | Dano |
|---|---|---|---|
| Asteroide pequeno | Desce reto | 1 | 10 |
| Asteroide grande | Desce reto, lento | 3 | 25 |
| Inimigo Kamikaze | Persegue o jogador | 2 | 30 |
| Inimigo Atirador | Para e atira projéteis | 4 | 15 (por projétil) |
| Inimigo Esquivo | Desce em zig-zag | 2 | 20 |
| Mini-Boss (raro) | Aparece em 1-2 fases pré-boss | 15 | 40 |

> Cada setor tem **variação de arte** dos inimigos (mesmo comportamento, visual diferente).

### Sistema de combo
- Multiplicador acumulável até **x5**
- Cada **10 inimigos destruídos sem tomar dano** = +1x
- O multiplicador afeta apenas o **XP** ganho na fase (não o BRL, para evitar abuse)
- **Reseta** ao tomar qualquer dano

### Pausa
- Botão de pausa habilitado durante a fase
- Menu da pausa: Continuar / Sair / Configurações
- **Sair = perde 1 vida** (anti-cheat: impede pausar para escapar de morte iminente)

### Configurável no painel admin
- ✅ HP máximo da nave (padrão e premium)
- ✅ Dano de cada tipo de inimigo
- ✅ HP de cada tipo de inimigo
- ✅ Velocidade de cada tipo de inimigo
- ✅ Lista de power-ups ativos (toggle individual por power-up)
- ✅ Taxa de drop de cada power-up
- ✅ Duração de cada power-up
- ✅ Habilitar/desabilitar combo
- ✅ Multiplicador máximo de combo (1x a 10x)
- ✅ Quantidade de inimigos para subir 1x no combo
- ✅ Toggle "munição infinita"
- ✅ Toggle "pausa habilitada"
- ✅ Cooldown do tiro automático (ms)
- ✅ Dano base do tiro padrão

## 9. Estrutura de Fase ✅

### Duração média
- Fases iniciais: **60s**
- Fases avançadas: **90s**
- Fases com boss: **sem timer** (até derrotar ou morrer)

### Estrutura de fase normal (F1, F2, F3, F4, F6, F7, F8, F9)

```
[0s — 5s]   Introdução: cenário rolando, sem inimigos
[5s — 50s]  3 ondas de inimigos (~15s cada)
            • Onda 1: inimigos básicos
            • Onda 2: mix
            • Onda 3: avançada
[50s — 60s] Pausa curta + power-up garantido (Reparo)
[Final]     "Stage Clear" → tela de resultados
```

### Estrutura de fase com boss (F5 e F10)

```
[0s — 30s]  2 ondas de warm-up (inimigos do setor)
[30s — 35s] "Warning! Boss approaching" (cenário trava, música muda)
[35s — fim] Boss fight sem timer
            • 3 fases de HP (100% → 50% → 25% → morte)
[Final]     Recompensa especial + tela de resultados
```

### Tabela de ondas por fase (MVP)

| Fase | Duração | Onda 1 | Onda 2 | Onda 3 | Especial |
|---|---|---|---|---|---|
| Treino | 45s | 5 asteroides | 3 asteroides | — | Tutorial guiado |
| F1 | 60s | 8 asteroides | 5 asteroides + 2 kamikaze | 3 asteroides | — |
| F2 | 60s | 10 asteroides | 6 asteroides + 3 kamikaze | 5 atiradores | — |
| F3 | 70s | 8 kamikaze | 5 atiradores + 4 esquivos | 6 asteroides | Power-up garantido |
| F4 | 75s | 10 esquivos | 6 atiradores + 4 kamikaze | 8 mix | Mini-boss raro |
| **F5 (boss)** | s/ timer | 5 atiradores | 8 mix do setor | — | **BOSS 1** |
| F6 | 70s | 12 mix setor 2 | 8 atiradores | 6 esquivos | — |
| F7 | 80s | 10 kamikaze | 6 atiradores + 5 esquivos | 8 mix | Power-up garantido |
| F8 | 85s | 8 esquivos | 10 mix | 8 atiradores | Mini-boss raro |
| F9 | 90s | 12 mix | 10 mix avançado | 8 mix difícil | 2 mini-bosses |
| **F10 (boss)** | s/ timer | 8 mix difícil | 10 mix avançado | — | **BOSS FINAL** |

### Ritmo de spawning (transição entre ondas)
- **Modelo híbrido:**
  - Onda dura no máximo **20s**
  - Se o jogador limpar antes de **15s**, a próxima onda começa imediatamente
  - Recompensa skill e mantém o ritmo

### Power-up de fim de fase
- Em fases **sem boss**: power-up de **Reparo** garantido antes do "Stage Clear"
- Em fases **com boss**: recompensa especial via boss (vide seção 10)

### XP por inimigo (bônus ao XP fixo da fase)
| Inimigo | XP |
|---|---|
| Asteroide pequeno | 1 |
| Asteroide grande | 2 |
| Kamikaze | 3 |
| Atirador | 5 |
| Esquivo | 4 |
| Mini-Boss | 50 |

> XP de inimigos é um **bônus pequeno** — o foco continua no XP fixo da fase.

### Configurável no painel admin
- ✅ Duração de cada fase (segundos)
- ✅ Editor de ondas por fase (lista com tipo + quantidade de inimigos)
- ✅ Tempo de cada onda
- ✅ Modo de transição entre ondas (fixo / limpar tela / híbrido)
- ✅ Timeout máximo por onda
- ✅ Power-up garantido de fim de fase (toggle + tipo do power-up)
- ✅ XP de cada tipo de inimigo
- ✅ Frequência de mini-bosses (% de chance por fase)
- ✅ Tempo do "warning" antes do boss
- ✅ Tempo do warm-up das fases de boss

## 10. Bosses ✅

### Visão geral

| Boss | Setor | Tema | Fase |
|---|---|---|---|
| **Asteroide-Mãe** | Setor 1 | Asteroide gigante com mini-asteroides orbitando | F5 |
| **Devorador de Sucata** | Setor 2 | Nave pirata reforçada com sucata | F10 |

### Estrutura geral (3 fases de HP)
| Fase de HP | Comportamento |
|---|---|
| 100% → 50% | Padrão básico, ataque previsível |
| 50% → 25% | Padrão acelerado + ataque secundário |
| <25% | Modo "berserk" — ataque agressivo + spawn de minions |

### Boss 1 — Asteroide-Mãe (F5)

| Stat | Valor |
|---|---|
| HP total | 500 |
| Tamanho | ~40% da largura da tela |
| Movimento | Lento, oscila esquerda-direita |
| Tempo médio | ~60s |

**Padrões de ataque:**
- **HP 100-50%:** Lança asteroides pequenos com telegrafia
- **HP 50-25%:** + Spawn de 2 mini-asteroides orbitando (precisam ser destruídos)
- **HP <25%:** + Charge attack (desce contra o jogador e volta)

**Recompensa:**
- BRL: R$ 0,40 (definido na seção 5)
- XP: 200 (definido na seção 2)
- Drop garantido: **Reparo + Bomba**

### Boss 2 — Devorador de Sucata (F10, boss final)

| Stat | Valor |
|---|---|
| HP total | 1000 |
| Tamanho | ~60% da largura da tela |
| Movimento | Rápido, padrões variados |
| Tempo médio | ~90s |

**Padrões de ataque:**
- **HP 100-50%:** Atira projéteis em leque (5 tiros)
- **HP 50-25%:** + Spawn de 2 atiradores como minions a cada 10s
- **HP <25%:** + Laser sweep horizontal (esquivar na vertical)

**Recompensa:**
- BRL: R$ 1,50 (definido na seção 5)
- XP: 350 (definido na seção 2)
- Drop garantido: **Bomba + Reparo + power-up exclusivo (a detalhar)**
- Conquista: Badge **"Conquistador"** no perfil
- Drop com chance baixa: **skin lendária** (a especificar na seção 13)

### Mecânicas comuns
- Barra de HP grande no topo da tela
- "Warning" visual nas transições (50% e 25%)
- Screen-shake ao tomar dano grande
- Música específica de boss; muda de tom no berserk (<25%)
- Slow-motion no golpe final + flash + tela "BOSS DEFEATED!"

### Continuar após morrer no boss
- Custo: **+2 créditos** (definido na seção 4)
- HP do boss **não é restaurado** (continua de onde parou)
- Mantém pressão e justiça

### Configurável no painel admin
- ✅ CRUD completo de bosses (criar, editar, ativar/desativar)
- ✅ HP total de cada boss
- ✅ Tamanho/escala visual
- ✅ Velocidade de movimento
- ✅ Editor de padrões de ataque por fase de HP (timer + tipo + dano)
- ✅ Limiares de transição de fase (50% e 25% ajustáveis)
- ✅ BRL extra do boss (separado da fase)
- ✅ XP extra do boss
- ✅ Lista de drops garantidos (power-ups)
- ✅ Chance de drop de skin/cosmético (%)
- ✅ Upload de música de fundo
- ✅ Toggle "modo berserk" da fase 3
- ✅ Toggle "HP do boss persiste no continue"

## 11. Persistência e UI do Mapa ✅

### Layout: **Mapa galáctico**
- Visual imersivo alinhado à temática espacial
- Cada setor é uma região do mapa com fundo temático próprio
- Setores apresentados em **scroll vertical único** (Setor 1 em cima, Setor 2 embaixo) com header destacado
  - "SETOR 1: Cinturão de Asteroides"
  - "SETOR 2: Zona de Detritos"

### Indicadores em cada nó de fase
- Número da fase (1, 2, 3...)
- Estrelas conquistadas (⭐⭐⭐ ou cinza)
- Cadeado se bloqueada (com nível necessário)
- Ícone de boss em F5 e F10
- Marca "NOVO" para fase recém-desbloqueada
- Recompensa em BRL exibida abaixo do nó

### Header (fixo no topo)
- Nome + foto do jogador
- Nível atual + barra de progresso para próximo nível
- XP total e XP para próximo nível
- Vidas atuais (ícones ❤️❤️❤️❤️🤍)
- Timer de próxima recarga de vida ("+1 vida em 23:14")
- Saldo de créditos
- Botão de compra (vidas/créditos)

### Modal de fase selecionada
- Nome + número da fase
- Recompensa em BRL com simulação por estrelas
- Estrelas atuais
- Custo em créditos
- Vidas atuais
- Botão **INICIAR** (ou **BLOQUEADA** com nível necessário)
- Botão **VER DETALHES** (inimigos, ondas, dicas)

### Tela pós-fase (postgame)
- Resultado: VITÓRIA! / DERROTA
- Animação de revelação das estrelas
- XP ganho com barra animada (level-up se aplicável)
- BRL ganho (com diferença em re-jogadas)
- Estatísticas: asteroides destruídos, dano sofrido, combo máximo, tempo
- Botões: Próxima fase / Re-jogar / Mapa

### Histórico por fase (acessível via "Ver Detalhes")
- Tentativas totais, vitórias, derrotas
- Melhor tempo
- Combo máximo histórico
- Asteroides destruídos totais
- Última vez jogada
- Estrelas obtidas
- BRL acumulado na fase

### Animações de progresso
- Cadeado quebrando ao desbloquear fase
- Pop-up "LEVEL UP! Você desbloqueou: [Fase X]"
- Confete + som especial ao 3⭐ pela 1ª vez
- Cinemática curta de explosão ao derrotar boss

### Persistência (banco de dados)

**Tabela `campaign_progress` (1 linha por jogador):**
- `google_uid` (PK)
- `current_level`
- `total_xp`
- `current_lives`
- `next_life_at`
- `streak_count`
- `daily_brl_earned`
- `daily_brl_reset_at`
- `total_stars`
- `created_at`, `updated_at`

**Tabela `campaign_stage_progress` (1 linha por jogador × fase):**
- `id` (PK)
- `google_uid`
- `stage_id`
- `stars` (0, 1, 2 ou 3)
- `best_time`
- `attempts`, `wins`, `losses`
- `total_brl_earned`
- `max_combo`
- `total_asteroids_destroyed`
- `last_played_at`, `first_completed_at`

### Configurável no painel admin
- ✅ Habilitar/desabilitar fases individualmente
- ✅ Customizar tema visual de cada setor (cor, background)
- ✅ Toggle "mostrar BRL no mapa"
- ✅ Toggle "mostrar estrelas no mapa"
- ✅ Descrição/dica de cada fase (texto editável)
- ✅ Animações ON/OFF (para low-end devices)
- ✅ Painel de progresso por jogador (visualização)
- ✅ Resetar progresso de um jogador (suporte)
- ✅ Conceder estrelas/XP manualmente (suporte)

## 12. Onboarding e Tutorial ✅

### Público-alvo do tutorial
- **Apenas jogadores novos no modo Campanha** (independente de experiência em outros modos)
- Foco em: vidas, XP, mapa, ondas, bosses (não mecânica básica)

### Tela de boas-vindas (1ª entrada)
Modal com 4 slides:
1. **Bem-vindo à Campanha!** — descrição do modo
2. **Sistema de Vidas** — 5 vidas que recarregam com o tempo
3. **XP e Níveis** — complete fases para subir de nível e desbloquear novas
4. **Estrelas** — vença sem dano para ganhar 3⭐ e mais BRL

Botão final: **"JOGAR FASE TREINO"**

### Fase Treino como tutorial guiado

```
[0s-3s]   "Mova com touch ou WASD" (highlight no controle)
[3s-8s]   "Tiros são automáticos" (asteroide grande demonstrativo)
[8s-15s]  Onda fácil — "Destrua todos os asteroides!"
[15s-25s] "Cuidado com seu HP" (asteroide em colisão direta)
[25s-35s] Power-up de Reparo — "Pegue power-ups para se recuperar!"
[35s-45s] "Boa sorte! Sua jornada começa agora."
```

### Tooltips contextuais (cada um aparece 1 vez)
- 1ª vida perdida → "Você perdeu uma vida! Vidas recarregam com o tempo."
- 1ª fase completada → "Parabéns! Você ganhou XP. Continue para subir de nível."
- 1ª estrela perdida → "Para 3⭐, complete sem tomar dano."
- 1ª aparição de power-up → "Power-ups dão habilidades especiais!"
- 1ª aparição de boss → "Este é um BOSS! Tem fases de HP. Esquive e ataque!"
- 1ª fase bloqueada → "Esta fase exige nível X. Continue jogando para desbloquear."

> Persistido no banco via flags em `campaign_tutorial_seen` por usuário.

### Cinemáticas (texto narrativo curto)
- **Setor 1 → Setor 2:** "Você atravessou o cinturão. Agora enfrenta os destroços de uma guerra antiga..."
- **Antes do boss final:** "O Devorador de Sucata espreita as profundezas. Esta é sua última missão."

> Cada cinemática aparece **1 vez** por jogador e pode ser pulada.

### Política de pular
- ✅ Tela de boas-vindas: **pode pular** ("Pular tutorial")
- ❌ Fase treino: **não pode ser pulada** (mas dá XP e custa pouco)
- ✅ Tooltips contextuais: **podem ser fechados**
- ✅ Cinemáticas: **podem ser puladas**

### Botão de "rever tutorial"
- Botão **"?"** no header do mapa
- Reabre a tela de boas-vindas (4 slides)
- Mostra tooltip ao tocar/passar em cada elemento (vidas, XP, créditos, etc)

### Configurável no painel admin
- ✅ Habilitar/desabilitar tela de boas-vindas
- ✅ Editar slides (título, texto, imagem)
- ✅ Habilitar/desabilitar fase treino como tutorial
- ✅ Editar mensagens da fase treino (timer + texto)
- ✅ Habilitar/desabilitar cada tooltip contextual individualmente
- ✅ Editar texto de cada tooltip
- ✅ Editar texto das cinemáticas
- ✅ Upload de imagens de cada cinemática
- ✅ Toggle "permitir pular cinemática"
- ✅ Toggle "tutorial obrigatório" (força fase treino antes de F1)

## 13. Engajamento Diário/Semanal ✅

> **Regra global:** Recompensas de engajamento são sempre em **BRL** ou **Vidas** — nunca em créditos (créditos são moeda paga; entregar grátis dilui a monetização).

### Missões Diárias (3 por dia, reset à meia-noite)

| Missão (template) | Recompensa |
|---|---|
| Complete 2 fases hoje | R$ 0,15 |
| Destrua 50 asteroides | R$ 0,10 |
| Faça 3⭐ em qualquer fase | +1 vida |
| Ganhe 100 XP hoje | R$ 0,15 |
| Derrote 1 mini-boss | +1 vida + R$ 0,10 |

### Streak Diário (login)

| Dia | Recompensa |
|---|---|
| 1 | R$ 0,05 |
| 2 | R$ 0,10 |
| 3 | +1 vida |
| 4 | R$ 0,20 |
| 5 | +2 vidas |
| 6 | R$ 0,30 |
| 7 | **R$ 0,50 + 2 vidas** |
| 8+ | loop volta ao dia 1 |

> Ao perder 1 dia, volta ao dia 1.

### Missões Semanais (4 por semana, reset domingo à meia-noite)

| Missão | Recompensa |
|---|---|
| Complete 10 fases nesta semana | R$ 1,00 |
| Faça 3⭐ em 5 fases | R$ 2,00 + 3 vidas |
| Derrote o Devorador de Sucata | R$ 3,00 |
| Atinja nível 15 | R$ 1,50 + 5 vidas |

### Eventos Especiais (sob demanda via admin)
- Sem evento fixo no MVP — admin cria com calendário (data início/fim)
- Banner visível na tela do mapa
- Exemplos:
  - "Fim de Semana de Recompensas" — todas as fases pagam 2x BRL
  - "Maratona de Bosses" — bosses dão +50% de XP por 3 dias
  - "Vidas Infinitas" — 24h de vidas ilimitadas (mensal)
  - "Triple Star" — 3⭐ em qualquer fase dá power-up exclusivo
- Recompensas dos eventos: sempre **multiplicadores** ou **vidas/BRL bônus**, nunca créditos

### Notificações Web Push (opt-in)
- Vidas cheias (volta a jogar)
- Missão diária expirando (1h antes da meia-noite)
- Evento especial começando
- Re-engagement após 3 dias sem jogar

### Conquistas (Achievements)
~15-20 no MVP. Recompensas sempre em BRL ou Vidas.

| Conquista | Recompensa |
|---|---|
| 🏆 Primeira Vitória (F1) | R$ 0,05 |
| 🏆 Estrela Cadente (1ª 3⭐) | R$ 0,10 |
| 🏆 Asteroides? Sem problema! (1.000 destruídos) | R$ 0,30 |
| 🏆 Conquistador do Setor 1 | R$ 0,50 + 3 vidas |
| 🏆 Imperador Galáctico (todas as fases 3⭐) | R$ 5,00 + 7 vidas |
| 🏆 Bilionário Espacial (R$ 50 acumulado) | R$ 1,00 |
| 🏆 Sobrevivente (5 fases sem usar continue) | R$ 0,30 + 2 vidas |

> Lista completa de 15-20 conquistas a definir no momento da implementação.

### Ranking dedicado
- **Ranking permanente** da campanha (estrelas totais, nível, BRL acumulado)
- **Ranking semanal** "mais XP ganho na semana" (reseta toda segunda)

### Configurável no painel admin
- ✅ CRUD de missões diárias (templates, recompensas restritas a BRL/Vidas)
- ✅ Quantidade de missões por dia (default: 3)
- ✅ Tabela de streak (dia + recompensa)
- ✅ CRUD de missões semanais
- ✅ CRUD de eventos especiais (calendário com início/fim, descrição, multiplicadores)
- ✅ Toggle por tipo de notificação web push
- ✅ Texto editável de cada notificação
- ✅ CRUD de conquistas (nome, descrição, condição, recompensa em BRL/vidas)
- ✅ Toggle "ranking semanal da campanha"
- ✅ Visualização de evento ativo no momento

## 14. Monetização Adicional ✅

### Já decidido em seções anteriores
- ✅ Custo de fase (1-2 créditos, seção 4)
- ✅ Continuar após game over (+2 créditos, seção 4)
- ✅ Comprar vidas (1 vida = 1 crédito; pacote 5 = 4 créditos; refill = 3 créditos, seção 3)
- ✅ Benefícios Premium (recarga 2x e +2 vidas máx, seção 3)

### Itens novos do MVP

#### 1. Skip de Fase
- Após **3 tentativas falhas** numa fase, libera o botão **"Pular Fase"**
- Custo: **5 créditos**
- Concede **1⭐** automaticamente (não dá 2⭐ ou 3⭐)
- Não entrega o BRL de 1ª vez? **Entrega normal** (decisão: o jogador pagou por "vencer")
- Jogador pode voltar depois e re-jogar para tentar 3⭐
- Configurável: quantidade de tentativas até liberar + custo + se entrega BRL

#### 2. Pacote "Acelerar Setor"
- Disponível após desbloqueio do setor
- Compra setor completo (5 fases) com **1⭐ em cada**
- Custo: **20 créditos** (Setor 1) e **30 créditos** (Setor 2)
- **Não entrega BRL** das fases (apenas desbloqueio + 1⭐)
- Jogador pode revisitar fases para fazer 2⭐/3⭐ e ganhar a diferença
- Configurável: toggle + custo por setor

#### 3. Skins de Nave (cosmético, não afeta gameplay)

| Skin | Tema | Custo |
|---|---|---|
| Nave Padrão | Default | grátis |
| Falcon Vermelho | Vermelha agressiva | 50 créditos |
| Phantom Roxo | Roxa estilizada | 50 créditos |
| Golden Wing | Dourada premium | 100 créditos |
| Skin Lendária do Boss Final | Drop raro do F10 | apenas drop (não vendida) |

- Configurável: CRUD completo no admin (nome, custo, sprite, disponibilidade, descrição)

#### 4. Triple Star Booster
- Item consumível
- **Efeito:** próxima fase começa com **escudo ativo** (bloqueia 1 dano)
- Custo: **3 créditos**
- Configurável: efeito + custo + toggle disponibilidade

### Itens explicitamente **descartados** do MVP
- ❌ **Battle Pass Mensal** — adiciona complexidade demais; reavaliar após validação do modo
- ❌ **Recompensa por Ver Anúncio** (na campanha) — fora do escopo inicial
- ❌ **Loja Unificada da Campanha** — sem tela "LOJA" centralizada no MVP

> Como não há loja unificada, cada item será oferecido **contextualmente**:
> - Vidas: pelo header do mapa (modal de compra direto)
> - Skip de Fase: aparece como botão na tela de game-over após 3 tentativas
> - Acelerar Setor: card promocional no mapa após desbloqueio
> - Skins: tela de seleção de nave no pré-jogo
> - Triple Star Booster: oferta no modal de seleção de fase

### Configurável no painel admin
- ✅ Toggle de cada item (skip, acelerar setor, skins, booster)
- ✅ Custo de cada item (editável)
- ✅ Quantidade de tentativas para liberar skip de fase
- ✅ Toggle "skip entrega BRL" (sim/não)
- ✅ CRUD de skins (nome, custo, sprite, disponibilidade, descrição)
- ✅ Promoções: % de desconto temporário em itens (com data início/fim)
- ✅ Toggle global "monetização adicional ativa" (kill-switch)

## 15. Anti-cheat e Validações ✅

### Princípio
**Nunca confiar no cliente.** Toda decisão importante (vidas, XP, BRL, fase concluída) é validada no servidor.

### Validações em `campaign-start.php`
- Validar Google UID (autenticação)
- Validar se a fase existe e está habilitada
- Validar nível mínimo do jogador para a fase
- Validar se o jogador tem vidas
- Validar se tem créditos suficientes
- Validar que não existe outra sessão ativa (anti multi-sessão)
- Debitar créditos (vida só é consumida ao falhar, mas a sessão é reservada)
- Gerar **token JWT** único (válido por: tempo da fase × 1.5)
- Salvar `campaign_session` no banco com seed RNG

### Validações em `campaign-end.php`
- Validar token JWT
- Validar tempo decorrido (mínimo 80% da duração; máximo 200%)
- Validar quantidade de asteroides destruídos vs total possível de spawn
- Validar BRL reportado (não pode exceder máximo da fase × 1.5)
- Validar XP reportado (não pode exceder máximo da fase × 1.5)
- Validar combo máximo (limite plausível)
- Validar HP final (0-100)
- Servidor **calcula** as estrelas (cliente não escolhe)
- Verificar se a sessão já foi finalizada (anti-replay)
- Marcar sessão como `completed`
- Aplicar limite diário de BRL antes de creditar

### Cálculo de estrelas no servidor
Servidor recebe `damage_taken`, `time_elapsed`, `enemies_killed` e decide:
| Condição | Estrelas |
|---|---|
| `damage_taken` = 0 | ⭐⭐⭐ |
| `damage_taken` ≤ 50% HP máx | ⭐⭐ |
| `damage_taken` < 100% HP máx | ⭐ |
| `damage_taken` ≥ 100% HP máx | derrota (0⭐) |

### Validação de progresso entre fases
- Iniciar fase X exige `level >= requisito da fase X` (server-side)
- Pular fase X exige `tentativas >= 3` (contador persistido)
- Compras de skins/boosters validam saldo de créditos antes de debitar

### Anti-replay e tokens únicos
- Cada sessão gera **um JWT único** com `session_id`, `stage_id`, `google_uid`, `expires_at`
- Token armazenado no banco — se já usado para finalizar, é rejeitado
- Sessões expiradas após `tempo_da_fase × 2` são marcadas automaticamente como abandonadas

### Itens explicitamente **descartados** do MVP
- ❌ **Heartbeat** durante a fase (cliente reportando "estou vivo" a cada 10s)
- ❌ **Logs detalhados de auditoria** em tabela dedicada (`campaign_anticheat_log`)
- ❌ **Ações automáticas de bloqueio** (sessão em "review", BRL pendente, bloqueio de conta)
- ❌ **Rate limiting** específico (máx N fases/min, máx N compras/min, máx N sessões/dia)

> ⚠️ **Observação:** essas funcionalidades aumentariam a robustez do anti-cheat. Reavaliar após o lançamento conforme aparecerem casos de abuso.

### Configurável no painel admin
- ✅ Tolerância de tempo da fase (% mínimo e máximo — default 80%/200%)
- ✅ Tolerância de BRL (% acima do máximo — default 50%)
- ✅ Tolerância de XP (% acima do máximo — default 50%)
- ✅ Tempo de expiração da sessão JWT (multiplicador sobre duração da fase)
- ✅ Limite máximo de combo plausível por fase

## 16. Integrações com o Site
> ⏳ **Pendente de decisão**

## 17. Versão / Lançamento
> ⏳ **Pendente de decisão**

## 18. Painel Administrativo
> ⏳ **A consolidar conforme decisões avançam**

Página: `admin/pages/campaign.php`

Funcionalidades previstas (vão sendo adicionadas conforme decisões):
- Gerenciar XP por fase ✅
- Gerenciar tabela de níveis ✅
- Gerenciar desbloqueios por nível ✅
- Gerenciar Sistema de Vidas (máx, recarga, consumo, custos, premium, ilimitado) ✅
- Gerenciar Custo em Créditos (setor, fase, treino, boss, re-jogada, continuar, promoções) ✅
- Gerenciar Recompensas em BRL (base por fase, estrelas, re-jogada, streak, limite diário, eventos) ✅
- Gerenciar Resolução/Orientação Mobile (canvas, rotação, controles, sensibilidade) ✅
- Gerenciar Scroll/Parallax (velocidade, camadas, tema por setor, comportamento no boss) ✅
- Gerenciar Mecânicas de Gameplay (HP, inimigos, power-ups, combo, pausa, tiros) ✅
- Gerenciar Estrutura de Fase (duração, ondas, transições, XP por inimigo, mini-bosses) ✅
- Gerenciar Bosses (CRUD, HP, padrões por fase, drops, música, modo berserk) ✅
- Gerenciar Persistência e UI do Mapa (fases, temas, descrições, animações, progresso por jogador) ✅
- Gerenciar Onboarding e Tutorial (slides, fase treino, tooltips, cinemáticas, toggles) ✅
- Gerenciar Engajamento (missões diárias/semanais, streak, eventos, notificações, conquistas, ranking) ✅
- Gerenciar Monetização Adicional (skip, acelerar setor, skins, booster, promoções, kill-switch) ✅
- Gerenciar Anti-cheat (tolerâncias de tempo/BRL/XP, expiração JWT, combo máximo) ✅

---

## Arquivos planejados (visão geral, evolui conforme decisões)

### Frontend
- `campaign.html` — mapa de fases
- `campaign-game.html` — tela de jogo
- `js/campaign-map.js`
- `js/campaign-config.js`
- `js/campaign-engine.js`
- `js/campaign-boss.js`

### Backend
- `api/campaign-start.php`
- `api/campaign-end.php`
- `api/campaign-progress.php`
- `api/campaign-config.php` (público — lê configs do admin)
- `api/admin/campaign-*.php` (CRUD do admin)

### Admin
- `admin/pages/campaign.php`

### Banco de Dados
- `campaign_stages` — definição estática
- `campaign_progress` — progresso por usuário
- `campaign_xp_table` — curva de XP por nível
- `campaign_unlocks` — XP necessário por fase
- `campaign_bosses` — definição dos bosses
- `campaign_settings` — configs gerais (vidas, cooldown, etc)
