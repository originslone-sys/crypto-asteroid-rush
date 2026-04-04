# Analise de Infraestrutura para Modo PvP (Nave vs Nave)

**Data:** 2026-04-04
**Status:** Analise / Planejamento
**Autor:** Equipe de Desenvolvimento

---

## 1. Infraestrutura Atual

### Stack em Producao

| Componente | Configuracao |
|---|---|
| **Plataforma** | Google Cloud Run |
| **Regiao** | us-west1 |
| **CPU** | 2 vCPUs por instancia |
| **Memoria** | 2Gi por instancia |
| **Min Instancias** | 3 (always-on) |
| **Concurrency** | 50 requests por instancia |
| **Timeout** | 300 segundos |
| **Banco de Dados** | Cloud SQL MySQL (instancia: `unobix`) |
| **Servidor** | Nginx + PHP 8.2-FPM (Alpine) |
| **Comunicacao** | REST API puro (HTTP POST/GET) |
| **Cache** | Nenhum (sem Redis/Memcached) |
| **Filas** | Nenhuma (Cloud Scheduler com cron HTTP a cada 10min) |
| **State** | Stateless - game state vive no client, validado no server no fim |

### Arquitetura Atual (Single-Player)

```
Browser (HTML5 Canvas)
    |
    | REST API (HTTP)
    v
Cloud Run (Nginx + PHP-FPM)
    |
    | Unix Socket / TCP
    v
Cloud SQL (MySQL)
```

- Jogo single-player com sessoes de 3 minutos
- Estado do jogo calculado no client, validado no server ao final
- Sem comunicacao real-time durante gameplay
- PHP-FPM com ~19 workers dinamicos por instancia

---

## 2. Requisitos do Modo PvP Real-Time

Um modo PvP onde uma nave joga contra outra nave precisa de:

1. **Comunicacao bidirecional em tempo real** (~60 updates/segundo por jogador)
2. **Matchmaking** (emparelhar jogadores por skill/regiao)
3. **Sincronizacao de estado** (posicao das 2 naves, tiros, asteroides)
4. **Latencia baixa** (<100ms idealmente)
5. **Sessoes persistentes** (conexao WebSocket mantida durante toda a partida)
6. **Server-authoritative** (servidor como autoridade para prevenir trapaças)

---

## 3. Por Que a Infraestrutura Atual NAO Suporta PvP Real-Time

### 3.1 Cloud Run nao e adequado para WebSockets persistentes
- Desenhado para requests stateless e curtos
- Sem sticky sessions - cada request pode ir para instancia diferente
- Dois jogadores em PvP cairiam em instancias diferentes
- Timeout maximo de 3600s para streaming, mas nao e ideal

### 3.2 PHP-FPM nao e ideal para conexoes persistentes
- Modelo request-response: cada request ocupa um worker
- WebSocket manteria o worker preso durante toda a partida (3+ min)
- Com ~19 workers por instancia, poucas partidas PvP saturariam os workers
- PHP nao tem ecossistema forte para real-time (vs Node.js/Go)

### 3.3 Sem camada de estado compartilhado
- Nao existe Redis ou Pub/Sub para comunicacao entre instancias
- Cloud Run escala horizontalmente, mas instancias sao isoladas
- PvP precisa que 2 jogadores compartilhem estado em tempo real

### 3.4 Sem sistema de mensageria
- Sem Redis Pub/Sub, Google Pub/Sub, ou message broker
- Nao ha como uma instancia notificar outra sobre eventos de jogo

### 3.5 MySQL como unico ponto de sincronizacao
- Polling no MySQL para sync PvP geraria latencia de 200-500ms+
- A cada tick do jogo (16ms em 60fps), query no Cloud SQL e inviavel

---

## 4. Solucao Proposta: Game Server PvP Dedicado

### 4.1 Arquitetura

```
                                                    ┌──────────────────────────┐
                                                    │   PvP Game Server        │
                                                    │   (Node.js + Socket.io)  │
                                                    │   Compute Engine VM      │
┌─────────────┐   REST (auth, creditos, resultado)  │                          │
│ Backend PHP  │◄──────────────────────────────────►│   - WebSocket Server     │
│ Cloud Run    │         VPC Interna                 │   - Matchmaking Engine   │
│              │                                    │   - Game Loop PvP        │
│ - Auth       │                                    │   - Sync de Estado       │
│ - Payments   │                                    └──────────┬───────────────┘
│ - Rankings   │                                               │
│ - Credits    │                                               │ VPC Interna
│ - Anti-cheat │                                               │
└──────┬───────┘                                    ┌──────────▼───────────────┐
       │                                            │   Memorystore (Redis)    │
       │                                            │                          │
       ▼                                            │   - Salas PvP            │
┌──────────────┐                                    │   - Fila Matchmaking     │
│ Cloud SQL    │                                    │   - Game State Cache     │
│ (MySQL)      │                                    └──────────────────────────┘
│              │
│ - users      │       ┌─────────────────┐
│ - sessions   │       │ Serverless VPC  │
│ - transactions│◄─────│ Connector       │──── Conecta Cloud Run a VPC
└──────────────┘       └─────────────────┘
```

### 4.2 Fluxo do Jogador no PvP

```
1. Player abre modo PvP no frontend
           │
           ▼
2. Frontend chama backend PHP: "user tem creditos?"
   POST /api/pvp-authorize.php { google_uid }
           │
           ▼
3. Backend PHP valida e retorna token JWT temporario
   { token, google_uid, credits, display_name, expiry }
           │
           ▼
4. Frontend conecta via WebSocket ao Game Server PvP
   ws://game-server-ip:3000 + token JWT
           │
           ▼
5. Game Server valida token (chave compartilhada ou callback ao PHP)
           │
           ▼
6. Game Server coloca jogador na fila de matchmaking (Redis)
           │
           ▼
7. Quando 2 jogadores prontos → cria sala → inicia partida
           │
           ▼
8. DURANTE A PARTIDA: tudo no Game Server (WebSocket puro)
   - Posicao das naves
   - Tiros disparados
   - Colisoes com asteroides
   - Dano entre jogadores
   - Score em tempo real
           │
           ▼
9. FIM DA PARTIDA: Game Server chama backend PHP
   POST /api/pvp-result.php { winner, loser, stats, session_token }
           │
           ▼
10. Backend PHP credita/debita saldos, registra em game_sessions e transactions
```

### 4.3 Separacao de Responsabilidades

| Responsabilidade | Quem Faz | Justificativa |
|---|---|---|
| Autenticacao | Backend PHP (existente) | Ja tem Firebase + users table |
| Creditos/Saldo | Backend PHP (existente) | Ja tem toda logica financeira |
| Matchmaking | Game Server PvP (novo) | Precisa de Redis + tempo real |
| Game Loop PvP | Game Server PvP (novo) | Precisa de WebSocket + baixa latencia |
| Resultado Final | Backend PHP (existente) | Mantem consistencia financeira |
| Rankings PvP | Backend PHP (existente) | Ja tem infra de ranking |
| Anti-cheat PvP | Game Server PvP (novo) | Server-authoritative no PvP |

### 4.4 O que Reutiliza vs O que Cria

**Reutiliza do backend atual (zero mudanca):**
- Toda autenticacao Firebase/Google
- Sistema de creditos e saldo BRL
- Sistema de transacoes e ledger
- Withdrawal/deposit via ZettPay
- Admin panel
- Rankings (expandir para incluir PvP)

**Novos endpoints no backend PHP (mudanca minima):**
- `pvp-authorize.php` - gera token JWT para entrar no PvP
- `pvp-validate-token.php` - valida token (chamado pelo Game Server)
- `pvp-result.php` - recebe resultado e credita/debita saldos

**Novo servico (Game Server PvP):**
- WebSocket server (Socket.io ou ws)
- Matchmaking engine
- Game loop server-authoritative
- Conexao com Redis para estado em tempo real

---

## 5. Recursos Google Cloud Necessarios

### 5.1 Compute Engine (Game Server PvP)

| Configuracao | Valor |
|---|---|
| **Tipo de Maquina** | e2-small (2 vCPU, 2GB) ou e2-medium (2 vCPU, 4GB) |
| **Regiao** | us-west1 (mesma do Cloud Run e Cloud SQL) |
| **Sistema Operacional** | Ubuntu 22.04 LTS ou Debian 12 |
| **Rede** | VPC default |
| **IP Externo** | Sim (para WebSocket dos players) |
| **Disco** | 10GB SSD (padrao, suficiente) |
| **Custo Estimado** | ~$15-25/mes (e2-small) ou ~$25-50/mes (e2-medium) |

**Software na VM:**
- Node.js 20 LTS
- Socket.io ou ws (WebSocket library)
- PM2 (process manager para Node.js)

### 5.2 Memorystore (Redis)

| Configuracao | Valor |
|---|---|
| **Tier** | Basic (sem replica, mais barato) |
| **Capacidade** | 1GB |
| **Regiao** | us-west1 |
| **Rede** | VPC default |
| **Versao** | Redis 7.x |
| **Custo Estimado** | ~$30-55/mes |

**Uso previsto:**
- Fila de matchmaking (SORTED SET por tempo de espera)
- Estado das salas PvP ativas (HASH por sala)
- Cache de tokens de autorizacao (STRING com TTL)
- Pub/Sub para eventos entre processos (se escalar para multi-processo)

### 5.3 VPC e Firewall

| Configuracao | Valor |
|---|---|
| **Rede** | default (ja existente, 42 sub-redes) |
| **Custo** | Gratuito |

**Regras de Firewall necessarias:**

| Regra | Direcao | Origem | Destino | Porta | Protocolo |
|---|---|---|---|---|---|
| `allow-websocket` | Ingress | 0.0.0.0/0 | Tag: pvp-server | 3000 | TCP |
| `allow-health-check` | Ingress | 35.191.0.0/16, 130.211.0.0/22 | Tag: pvp-server | 3000 | TCP |

> Comunicacao interna VM ↔ Redis ja e liberada na VPC default.

### 5.4 Serverless VPC Connector

| Configuracao | Valor |
|---|---|
| **Servico** | Acesso VPC sem servidor |
| **Regiao** | us-west1 |
| **Rede** | default |
| **Custo** | ~$7-10/mes (e2-micro) |

**Finalidade:** Permitir que o Cloud Run (backend PHP) acesse recursos na VPC interna (Memorystore Redis e Compute Engine VM) por IP privado, sem expor a internet.

---

## 6. Resumo de Custos

### Cenario Minimo (MVP/Validacao)

| Recurso | Especificacao | Custo/Mes |
|---|---|---|
| Compute Engine | e2-small (2 vCPU, 2GB) | ~$15 |
| Game state | Em memoria Node.js (sem Redis) | $0 |
| VPC/Firewall | Ja existente | $0 |
| **Total** | | **~$15/mes** |

> Limitacao: se a VM reiniciar, partidas em andamento sao perdidas.

### Cenario Recomendado (Producao)

| Recurso | Especificacao | Custo/Mes |
|---|---|---|
| Compute Engine | e2-medium (2 vCPU, 4GB) | ~$35 |
| Memorystore Redis | Basic, 1GB | ~$35 |
| Serverless VPC Connector | e2-micro | ~$7 |
| VPC/Firewall | Ja existente | $0 |
| **Total** | | **~$77/mes** |

### Cenario Escalado (Alto Trafego)

| Recurso | Especificacao | Custo/Mes |
|---|---|---|
| Compute Engine | e2-standard-2 (2 vCPU, 8GB) | ~$50 |
| Memorystore Redis | Basic, 2GB | ~$60 |
| Serverless VPC Connector | e2-micro | ~$7 |
| Load Balancer (se 2+ VMs) | Network LB | ~$20 |
| **Total** | | **~$137/mes** |

---

## 7. Comunicacao Segura entre Servicos

### Autenticacao entre Game Server e Backend PHP

```
Backend PHP  ◄──► Game Server PvP
     │                    │
     │  Opcoes:           │
     │  1. Shared secret (HMAC) nos headers
     │  2. JWT com chave simetrica
     │  3. Service account do GCP (IAM)
     │
     └── Ambos na mesma VPC do GCP
         comunicacao via IP interno
         sem exposicao a internet publica
         latencia ~1ms
```

**Recomendacao:** JWT com chave simetrica compartilhada entre os servicos via variavel de ambiente. Simples, seguro, e nao depende de servicos externos.

---

## 8. Capacidade Estimada

### Com e2-small (2 vCPU, 2GB)
- ~100-200 conexoes WebSocket simultaneas
- ~50-100 partidas PvP simultaneas
- Suficiente para centenas de jogadores ativos

### Com e2-medium (2 vCPU, 4GB)
- ~500-1000 conexoes WebSocket simultaneas
- ~250-500 partidas PvP simultaneas
- Suficiente para milhares de jogadores ativos

---

## 9. Alternativas ao PvP Real-Time

| Tipo de PvP | Viavel na Infra Atual? | Esforco | Custo Adicional |
|---|---|---|---|
| **Ghost/Replay** (joga contra gravacao) | SIM | Baixo | Zero |
| **Turn-Based** (turnos alternados) | SIM (com limitacoes) | Medio | Zero |
| **Real-Time** (tempo real) | NAO | Alto | $15-137/mes |

---

## 10. Proximos Passos (quando for implementar)

1. **Definir tipo de PvP** (real-time, ghost, ou turn-based)
2. **Provisionar recursos** (Compute Engine + Redis + Firewall)
3. **Desenvolver Game Server** (Node.js + Socket.io)
4. **Criar endpoints PHP** (pvp-authorize, pvp-validate-token, pvp-result)
5. **Desenvolver frontend PvP** (nova tela de matchmaking + gameplay PvP)
6. **Configurar VPC Connector** (Cloud Run → VPC interna)
7. **Testes de carga** (verificar latencia e capacidade)
8. **Deploy e monitoramento**
