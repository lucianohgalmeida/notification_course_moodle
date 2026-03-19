# PRD — Notificações Automáticas de Cursos (Standalone)

**Cliente:** Educação Paralímpica  
**Versão:** 3.1  
**Data:** Março de 2026  
**Status:** Em Revisão  
**Equipe:** TechEduConnect

---

## Sumário

1. [Visão Geral](#1-visão-geral)
2. [Modelo de Notificações](#2-modelo-de-notificações)
3. [Fluxo Funcional](#3-fluxo-funcional)
4. [Painel de Gerenciamento](#4-painel-de-gerenciamento)
5. [Variáveis Dinâmicas e Templates](#5-variáveis-dinâmicas-e-templates)
6. [Arquitetura Técnica](#6-arquitetura-técnica)
7. [Agendamento — CRON](#7-agendamento--cron)
8. [Autenticação e Acesso](#8-autenticação-e-acesso)
9. [Frontend — Design System](#9-frontend--design-system)
10. [Requisitos Não Funcionais](#10-requisitos-não-funcionais)
11. [Plano de Fases](#11-plano-de-fases)
12. [Critérios de Aceite](#12-critérios-de-aceite)
13. [Glossário](#13-glossário)

---

## 1. Visão Geral

### 1.1 Objetivo

Desenvolver uma aplicação PHP standalone hospedada no mesmo servidor do Moodle da Educação Paralímpica, acessível em `/notification_course/`, para gerenciar e automatizar o envio de e-mails de notificação para estudantes matriculados em cursos. A aplicação não é um plugin — roda de forma independente, mas faz bootstrap do Moodle para aproveitar o banco de dados, o sistema de e-mail e a autenticação já configurados na plataforma.

### 1.2 Premissas Técnicas

| Decisão | Definição |
|---|---|
| Tipo de solução | Aplicação PHP standalone (não plugin) |
| Hospedagem | Mesmo servidor do Moodle, em `/notification_course/` |
| Banco de dados | Banco do Moodle + tabelas adicionais prefixadas `notifcourse_` |
| Envio de e-mail | `email_to_user()` nativo do Moodle |
| Autenticação | Sessão do Moodle — acesso restrito a administradores |
| Segurança de POST | `require_sesskey()` obrigatório em todas as ações POST |
| Idempotência de envio | `flock` no `cron.php` + `dedupe_key` único no log |
| Timezone | Persistência em UTC; exibição no timezone configurado no Moodle |
| Frontend | Tailwind CSS + CSS variables do shadcn/ui (sem React, sem build step) |
| Senha nos e-mails | Não enviada — aluno usa "Esqueci minha senha" do Moodle |

### 1.3 URLs

| Componente | URL |
|---|---|
| Moodle | `https://www.educacaoparalimpica.org.br/` |
| Aplicação | `https://www.educacaoparalimpica.org.br/notification_course/` |

---

## 2. Modelo de Notificações

Todo curso no Moodle tem uma `startdate` e uma `enddate`. Dentro desse período o admin cadastra **agendas** — cada agenda representa uma aula com data/hora e link do Zoom. O sistema opera em três camadas:

```
startdate                 agenda 1    agenda 2    agenda 3               enddate
    │                        │           │           │                      │
    ▼                        ▼           ▼           ▼                      ▼
[auto: início]        [auto: aula]  [auto: aula]  [auto: aula]      [auto: conclusão]
                      [+ reforço?]  [+ reforço?]  [+ reforço?]
```

### Camada 1 — Notificação de Início (automática)

- Gatilho: `mdl_course.startdate`
- Configuração: template global + antecedência em horas
- Criação: nenhuma ação manual necessária por curso
- Recorrência: uma vez por curso

### Camada 2 — Agendas Intermediárias (automáticas + reforço manual)

- Gatilho: data/hora de cada agenda criada pelo admin
- Criação: admin cadastra cada agenda dentro do período `startdate → enddate` do curso
- Link do Zoom: sugerido automaticamente a partir da agenda anterior (ou do campo do curso na primeira), editável a qualquer momento antes do disparo
- Disparo automático: CRON envia X horas antes da data/hora da agenda
- Disparo de reforço: admin pode acionar um envio adicional manual pelo painel para qualquer agenda
- Recorrência: N agendas por curso

### Camada 3 — Notificação de Conclusão (automática)

- Gatilho: `mdl_course.enddate`
- Configuração: template global + delay em horas após o término
- Criação: nenhuma ação manual necessária por curso
- Recorrência: uma vez por curso

### Regra de Link do Zoom nas Agendas

Ao cadastrar uma nova agenda, o campo `link_zoom` é pré-preenchido automaticamente:

1. Se já existe agenda anterior no mesmo curso → usa o link da agenda mais recente
2. Se é a primeira agenda do curso → usa o campo customizado `link_zoom` do curso no Moodle (`mdl_customfield_data`)
3. Se nenhum dos dois está preenchido → campo fica vazio, admin preenche manualmente

O link pode ser editado a qualquer momento enquanto o status da agenda for `pending`.

---

## 3. Fluxo Funcional

### 3.1 Fluxo Completo

```
Admin acessa o painel
        │
        ├── Configura templates globais (Camada 1 e Camada 3) — uma vez
        │
        └── Para cada curso:
                └── Cadastra agendas de aula (Camada 2)
                        ├── Seleciona o curso
                        ├── Informa data/hora da aula
                        ├── Confirma ou edita o link do Zoom (pré-preenchido)
                        ├── Ajusta o texto do e-mail se necessário
                        └── Salva — status: pending
        │
        ▼
CRON executa a cada hora
        │
        ├── Camada 1: cursos com startdate na janela → envia para alunos ativos
        ├── Camada 2: agendas com send_at na janela → envia para alunos ativos
        └── Camada 3: cursos com enddate + delay na janela → envia para alunos que acessaram
        │
        └── Todos os disparos → notifcourse_log (auto ou manual, sucesso ou falha)
```

---

### 3.2 Fluxo de Disparo — Camada 1 (Início)

```
CRON → SELECT mdl_course
       WHERE startdate ENTRE (AGORA + X horas) E (AGORA + X+1 horas)
       AND category IN (categorias ativas no painel)
       AND visible = 1
        │
        ▼
Para cada curso → busca alunos ativos
        │
        SELECT mdl_user JOIN mdl_user_enrolments + mdl_enrol + mdl_role_assignments
        WHERE matrícula ativa, usuário não suspenso/deletado, com e-mail,
              role de estudante no contexto do curso
        │
        ▼
Para cada aluno:
        ├── Já enviado? (log type='start', status='success') → pula
        └── Não enviado → substitui variáveis → email_to_user() → grava log
```

---

### 3.3 Fluxo de Disparo — Camada 2 (Agendas)

```
CRON → SELECT notifcourse_schedule
       WHERE status = 'pending'
       AND send_at <= AGORA
       AND (não há falha pendente OU retry já vencido)
        │
        ▼
Para cada agenda → busca alunos ativos do curso
        │
        ▼
Para cada aluno:
        ├── Já enviado para esta agenda? (log schedule_id + userid, status='success') → pula
        └── Não enviado → substitui variáveis → email_to_user() → grava log
        │
        ▼
Todos os alunos processados → atualiza notifcourse_schedule.status = 'sent'
```

**Disparo de reforço manual:**

O admin acessa a agenda pelo painel e clica em "Enviar Reforço". O sistema dispara imediatamente para todos os alunos ativos — independente de já terem recebido o disparo automático. O log registra `origin = 'manual'`.

Para evitar disparos acidentais, há cooldown de 10 minutos por agenda entre reforços manuais (validação server-side).

---

### 3.4 Fluxo de Disparo — Camada 3 (Conclusão)

```
CRON → SELECT mdl_course
       WHERE enddate ENTRE (AGORA - delay horas) E (AGORA)
       AND category IN (categorias ativas no painel)
        │
        ▼
Para cada curso → busca alunos que acessaram
        │
        SELECT mdl_user JOIN mdl_user_lastaccess + mdl_role_assignments
        WHERE courseid = ? AND timeaccess > 0
          AND role de estudante no contexto do curso
        │
        ▼
Para cada aluno:
        ├── Já enviado? (log type='end', status='success') → pula
        └── Não enviado → substitui variáveis → email_to_user() → grava log
```

---

### 3.5 Log de Todos os Disparos

Todo disparo — automático ou manual — gera um registro em `notifcourse_log`:

| Campo | Disparo automático | Disparo de reforço manual |
|---|---|---|
| `notification_type` | `start`, `lesson`, `end` | `lesson` |
| `origin` | `auto` | `manual` |
| `schedule_id` | ID da agenda (Camada 2) ou null | ID da agenda |
| `status` | `success` / `failed` / `abandoned` / `dry_run` | `success` / `failed` / `dry_run` |
| `attempts` | Incrementa a cada retentativa | 1 |
| `dedupe_key` | Única por envio automático elegível | Única por reforço (inclui lote manual) |

Falhas automáticas são retentadas com backoff progressivo (1h, 2h, 4h) até `max_attempts`. Após o limite, status muda para `abandoned` e o admin é alertado no dashboard.

`dedupe_key` é a barreira de idempotência no banco:
- Camada 1 auto: `auto:start:{courseid}:{userid}`
- Camada 2 auto: `auto:lesson:{scheduleid}:{userid}`
- Camada 3 auto: `auto:end:{courseid}:{userid}`
- Reforço manual: `manual:lesson:{scheduleid}:{userid}:{manual_dispatch_id}`

---

## 4. Painel de Gerenciamento

### 4.1 Dashboard (`index.php`)

- Totalizadores: enviados hoje, na semana, com falha, abandonados
- Próximas agendas — lista das 5 mais próximas com curso, data e status
- Últimos 10 disparos com origem (auto/manual) e status
- Alerta para falhas e abandonados pendentes

### 4.2 Agendas de Curso (`schedules.php`)

**Listagem:**

| Curso | Data da Aula | Envio Previsto | Link Zoom | Status | Origem | Ações |
|---|---|---|---|---|---|---|
| Atletismo — T1 | 15/03 14h | 15/03 12h | zoom.us/j/xxx | Pendente | — | Editar / Cancelar / Reforço |
| Bocha — T2 | 12/03 9h | 12/03 8h | zoom.us/j/yyy | Enviado | Auto | Ver log |
| Goalball | 10/03 10h | 10/03 9h | zoom.us/j/zzz | Enviado | Manual | Ver log |

**Criar nova agenda:**

1. Selecionar curso — dropdown com cursos ativos (dentro do período `startdate → enddate`)
2. Data e hora da aula
3. Antecedência do envio (1h, 2h, 6h, 12h, 24h antes)
4. Link do Zoom — pré-preenchido automaticamente, editável
5. Texto do e-mail — template padrão carregado, editável
6. Salvar → status `pending`

**Ações disponíveis por agenda:**

- **Editar** — disponível enquanto status for `pending`
- **Cancelar** — cancela o disparo automático (status → `cancelled`)
- **Enviar Reforço** — disparo imediato adicional, disponível em qualquer status
- **Enviar Reforço** — disparo imediato adicional, disponível em qualquer status, respeitando cooldown de 10 minutos por agenda
- **Ver log** — exibe todos os registros de envio daquela agenda

### 4.3 Configurações Globais (`settings.php`)

**Camada 1 — Início do Curso:**
- Categorias de cursos participantes
- Antecedência em horas
- Template de assunto e corpo

**Camada 3 — Conclusão do Curso:**
- Delay em horas após o término
- Template de assunto e corpo
- URL da pesquisa de reação

**Geral:**
- Tamanho do batch por ciclo CRON
- Número máximo de tentativas antes de `abandoned`
- Configuração de timezone de referência para exibição no painel (persistência segue UTC)

### 4.4 Histórico (`history.php`)

- Tabela paginada: data, curso, agenda, tipo, aluno, e-mail, origem, status, tentativas
- Filtros: curso, tipo, origem (auto/manual), período, status
- Exportação em CSV

### 4.5 Teste (`test.php`)

- Selecionar tipo (início, agenda, conclusão)
- Selecionar curso base
- Disparo apenas para o e-mail do administrador logado
- Log do resultado exibido na tela
- Opção de simulação (`dry-run`) sem envio real de e-mail

---

## 5. Variáveis Dinâmicas e Templates

### 5.1 Variáveis disponíveis em todos os tipos

| Variável | Origem |
|---|---|
| `{nome_aluno}` | `mdl_user.firstname + lastname` |
| `{login_moodle}` | `mdl_user.username` |
| `{nome_curso}` | `mdl_course.fullname` |
| `{link_esqueci_senha}` | `$CFG->wwwroot . '/login/forgot_password.php'` |

### 5.2 Variáveis exclusivas — Camada 1 (Início)

| Variável | Origem |
|---|---|
| `{data_inicio}` | `mdl_course.startdate` formatado |

### 5.3 Variáveis exclusivas — Camada 2 (Agendas)

| Variável | Origem |
|---|---|
| `{data_aula}` | `notifcourse_schedule.lesson_date` — data formatada |
| `{hora_aula}` | `notifcourse_schedule.lesson_date` — hora formatada |
| `{link_zoom}` | `notifcourse_schedule.zoom_link` |

### 5.4 Variáveis exclusivas — Camada 3 (Conclusão)

| Variável | Origem |
|---|---|
| `{data_termino}` | `mdl_course.enddate` formatado |
| `{link_pesquisa}` | `notifcourse_config` chave `end_survey_url` |

### 5.5 Templates Sugeridos

**Camada 1 — Início:**
```
Assunto: Seu curso começa em breve!

Olá, {nome_aluno}!

O curso "{nome_curso}" tem início em {data_inicio}.

Acesse com seu usuário: {login_moodle}
Esqueceu sua senha? {link_esqueci_senha}

Até lá!
Equipe Educação Paralímpica
```

**Camada 2 — Agenda de Aula:**
```
Assunto: Lembrete — Sua aula online começa em breve!

Olá, {nome_aluno}!

Sua aula do curso "{nome_curso}" via Zoom está agendada para hoje às {hora_aula}.

Link de acesso: {link_zoom}

Usuário Moodle: {login_moodle}
Esqueceu sua senha? {link_esqueci_senha}

Até já!
Equipe Educação Paralímpica
```

**Camada 3 — Conclusão:**
```
Assunto: Parabéns pela conclusão de {nome_curso}!

Olá, {nome_aluno}!

Você concluiu o curso "{nome_curso}" em {data_termino}. Parabéns!

Responda nossa Avaliação de Reação:
{link_pesquisa}

Equipe Educação Paralímpica
```

---

## 6. Arquitetura Técnica

### 6.1 Estrutura de Diretórios

```
/notification_course/
│
├── index.php                  ← Dashboard
├── schedules.php              ← Agendas de aula (Camada 2)
├── settings.php               ← Configurações globais (Camada 1 e 3)
├── history.php                ← Histórico de todos os disparos
├── test.php                   ← Modo de teste
├── bootstrap.php              ← Bootstrap do Moodle + verificação de admin
├── cron.php                   ← Ponto de entrada exclusivo do CRON
│
├── src/
│   ├── CourseChecker.php      ← Consulta cursos elegíveis e alunos via $DB
│   ├── ScheduleManager.php    ← CRUD das agendas de aula
│   ├── Mailer.php             ← Envio via email_to_user()
│   ├── NotifLog.php           ← Leitura e escrita em notifcourse_log
│   └── TemplateEngine.php     ← Substituição de variáveis nos templates
│
├── db/
│   └── schema.sql             ← DDL das tabelas notifcourse_*
│
├── templates/
│   ├── email_start.html       ← Template — início do curso
│   ├── email_lesson.html      ← Template — agenda de aula
│   └── email_end.html         ← Template — conclusão do curso
│
└── assets/
    └── app.css                ← CSS variables shadcn/ui + Tailwind
```

### 6.2 Tabelas do Banco de Dados

#### `notifcourse_schedule` — Agendas de aula

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | BIGINT PK | Identificador |
| `courseid` | BIGINT | FK `mdl_course.id` |
| `lesson_date` | BIGINT | Unix timestamp da aula |
| `send_at` | BIGINT | Unix timestamp do disparo automático |
| `zoom_link` | TEXT | Link do Zoom — editável até o disparo |
| `subject` | VARCHAR(255) | Assunto do e-mail |
| `body` | TEXT | Corpo do e-mail com variáveis |
| `status` | VARCHAR(20) | `pending`, `sent`, `cancelled` |
| `timecreated` | BIGINT | Data de criação |
| `createdby` | BIGINT | FK `mdl_user.id` — admin que criou |

#### `notifcourse_log` — Log de todos os disparos

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | BIGINT PK | Identificador |
| `userid` | BIGINT | FK `mdl_user.id` — destinatário |
| `courseid` | BIGINT | FK `mdl_course.id` |
| `schedule_id` | BIGINT | FK `notifcourse_schedule.id` (null para Camada 1 e 3) |
| `notification_type` | VARCHAR(10) | `start`, `lesson`, `end` |
| `origin` | VARCHAR(10) | `auto` ou `manual` |
| `manual_dispatch_id` | VARCHAR(64) | Identificador do lote de reforço manual (null para automático) |
| `dedupe_key` | VARCHAR(191) | Chave única de idempotência por envio |
| `timesent` | BIGINT | Unix timestamp do disparo |
| `status` | VARCHAR(20) | `success`, `failed`, `abandoned`, `dry_run` |
| `attempts` | TINYINT | Número de tentativas realizadas |
| `next_retry_at` | BIGINT | Próxima tentativa automática (null quando não aplicável) |
| `last_error` | TEXT | Último erro retornado no envio |
| `is_simulation` | TINYINT | 1 quando executado em `--dry-run` |

**Índices recomendados (mínimo):**
- `UNIQUE (dedupe_key)`
- índices para filtros: `(courseid, timesent)`, `(schedule_id, origin)`, `(status, next_retry_at)`, `(userid)`

#### `notifcourse_config` — Configurações globais

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | BIGINT PK | Identificador |
| `config_key` | VARCHAR(100) | Chave da configuração |
| `config_value` | TEXT | Valor |
| `timemodified` | BIGINT | Última modificação |

**Chaves previstas:** `start_subject`, `start_body`, `start_hours_before`, `end_subject`, `end_body`, `end_hours_after`, `end_survey_url`, `batch_size`, `max_attempts`, `display_timezone`.

#### `notifcourse_categories` — Categorias participantes

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | BIGINT PK | Identificador |
| `categoryid` | BIGINT | FK `mdl_course_categories.id` |
| `active` | TINYINT | 1 = ativa |
| `timecreated` | BIGINT | Data de inclusão |

`notifcourse_categories` é a fonte única para categorias participantes (evita divergência com configuração textual).

---

## 7. Agendamento — CRON

```bash
# Entrada no crontab do servidor
0 * * * * php /var/www/html/notification_course/cron.php >> /var/log/notifcourse.log 2>&1
# Simulação/homologação
# 0 * * * * php /var/www/html/notification_course/cron.php --dry-run >> /var/log/notifcourse-dryrun.log 2>&1
```

### Sequência de execução por ciclo

```
1. Adquire lock exclusivo via `flock` (evita sobreposição)
2. Processa Camada 1 — início de curso                    (batch de até N envios)
3. Processa Camada 2 — agendas pendentes e retries de aula (batch de até N envios)
4. Processa Camada 3 — conclusão de curso e retries        (batch de até N envios)
5. Registra timestamp/resumo da execução                   (exibido no dashboard/log)
6. Libera lock
```

### Proteção de acesso

```php
if (php_sapi_name() !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
```

Execução via CLI é o padrão recomendado em produção.

---

## 8. Autenticação e Acesso

```php
// bootstrap.php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());
```

O admin acessa o painel com o próprio login de administrador do Moodle — sem senha separada. Qualquer usuário sem o papel de administrador é redirecionado automaticamente pelo Moodle.

---

## 9. Frontend — Design System

Interface construída com **Tailwind CSS via CDN** e **CSS variables do shadcn/ui**, sem React e sem build step.

```html
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root {
    --background: 0 0% 100%;
    --foreground: 222.2 84% 4.9%;
    --primary: 221.2 83.2% 53.3%;
    --primary-foreground: 210 40% 98%;
    --muted: 210 40% 96.1%;
    --muted-foreground: 215.4 16.3% 46.9%;
    --border: 214.3 31.8% 91.4%;
    --radius: 0.5rem;
  }
</style>
```

| Componente | Uso |
|---|---|
| Card | Totalizadores do dashboard |
| Table | Lista de agendas e histórico |
| Badge | Status `pending` / `sent` / `failed` / `manual` |
| Button | Salvar, agendar, reforço, cancelar, testar |
| Input / Textarea | Formulário de agenda e templates |
| Select | Curso, antecedência, tipo |
| Alert | Feedback de ações e erros |
| Modal | Confirmação de cancelamento e reforço |

---

## 10. Requisitos Não Funcionais

### 10.1 Segurança e LGPD

- Acesso restrito via `require_capability('moodle/site:config', ...)`
- `require_sesskey()` obrigatório em todos os formulários/ações POST
- Nenhuma senha trafega nos e-mails
- Logs armazenam apenas metadados sem conteúdo de e-mail
- Remoção de registros de `notifcourse_log` por `userid` disponível para atender LGPD

### 10.2 Desempenho

- Batch configurável por camada (padrão: 50 envios por ciclo)
- Índices em `userid`, `courseid`, `send_at`, `status` e `schedule_id`
- Tempo esperado por ciclo CRON: < 30 segundos

### 10.3 Compatibilidade

- Moodle 4.0+, PHP 7.4+, MySQL/MariaDB e PostgreSQL, Apache ou Nginx

### 10.4 Portabilidade

Reaproveitável em outros clientes Moodle ajustando apenas o path do `require_once` no `bootstrap.php`.

---

## 11. Plano de Fases

| Fase | Nome | Entregável |
|---|---|---|
| 1 | Setup & Bootstrap | Aplicação instalada, conectada ao Moodle, acesso restrito a admins |
| 2 | Banco de Dados | Tabelas `notifcourse_*` criadas via `schema.sql` |
| 3 | CourseChecker | Consultas de cursos elegíveis, alunos ativos e alunos que acessaram |
| 4 | TemplateEngine & Mailer | Substituição de variáveis e envio via `email_to_user()` |
| 5 | Camada 1 e 3 (automáticas) | CRON processando início e conclusão com controle de duplicidade e log |
| 6 | Camada 2 — CRUD de Agendas | Criação, edição, cancelamento de agendas com pré-preenchimento do link Zoom |
| 7 | Camada 2 — Disparo automático e reforço manual | CRON processando agendas + disparo imediato pelo painel |
| 8 | Painel Completo | Dashboard, histórico com filtros, configurações globais, modo de teste, exportação CSV |
| 9 | Homologação | Testes com dados reais, ajustes, documentação de instalação |

---

## 12. Critérios de Aceite

### Must Have

1. Acesso ao painel restrito a administradores do Moodle
2. Admin consegue criar, editar, cancelar e visualizar agendas de aula (Camada 2)
3. Link do Zoom pré-preenchido na criação da agenda — editável antes do disparo
4. CRON dispara Camada 1 dentro da janela de horas configurada, uma vez por curso
5. CRON dispara agendas da Camada 2 no horário calculado (hora da aula − antecedência)
6. Disparo de reforço manual disponível para qualquer agenda pelo painel
7. CRON dispara Camada 3 após o delay configurado, uma vez por curso
8. Todos os disparos (automáticos e manuais) geram registro em `notifcourse_log` com `origin`
9. Falhas automáticas são retentadas até o limite configurado — após isso status `abandoned`
10. Variáveis dinâmicas substituídas corretamente em todos os templates
11. Histórico completo com filtros por tipo, origem, período e status
12. Exportação do histórico em CSV
13. Modo de teste envia apenas para o e-mail do administrador logado
14. CRON com lock (`flock`) e deduplicação por `dedupe_key` para evitar envios duplicados
15. Reforço manual respeita cooldown de 10 minutos por agenda
16. Modo `--dry-run` executa fluxo completo sem chamar `email_to_user()`

### Nice to Have

- Alerta por e-mail ao admin quando disparos forem marcados como `abandoned`
- Múltiplos templates por categoria de curso
- Relatório mensal de métricas de envio

---

## 13. Glossário

| Termo | Definição |
|---|---|
| Camada 1 | Notificação automática de início de curso baseada em `startdate` do Moodle |
| Camada 2 | Agenda de aula criada manualmente pelo admin, com disparo automático e opção de reforço manual |
| Camada 3 | Notificação automática de conclusão de curso baseada em `enddate` do Moodle |
| Agenda | Registro de uma aula específica com data/hora, link do Zoom e template de e-mail |
| Reforço manual | Disparo adicional imediato acionado pelo admin para uma agenda já existente |
| Bootstrap do Moodle | Inclusão do `config.php` do Moodle para acesso ao `$DB` e funções do core |
| `email_to_user()` | Função nativa do Moodle para envio de e-mails via SMTP configurado |
| `require_capability()` | Função do Moodle que verifica a permissão do usuário logado |
| `origin` | Campo do log que identifica se o disparo foi automático (`auto`) ou manual (`manual`) |
| Batch | Limite máximo de e-mails processados por camada por ciclo de CRON |
| `notifcourse_` | Prefixo das tabelas próprias da aplicação no banco do Moodle |
| LGPD | Lei Geral de Proteção de Dados — lei brasileira de proteção de dados pessoais |

---

*Documento elaborado pela equipe TechEduConnect para o projeto Educação Paralímpica — v3.1, Março de 2026.*
