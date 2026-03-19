# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Visão Geral do Projeto

Aplicação PHP standalone para notificações automáticas de cursos, hospedada no mesmo servidor do Moodle (Educação Paralímpica). Roda em `/notification_course/`, faz bootstrap do Moodle para usar seu banco de dados, sistema de e-mail (`email_to_user()`) e autenticação. **Não é um plugin Moodle.**

**Stack:** PHP 7.4+, Moodle 4.0+, MySQL/MariaDB ou PostgreSQL, Tailwind CSS via CDN + Alpine.js (sem React, sem build step).

## Arquitetura

### Três Camadas de Notificação

- **Camada 1 (Início):** Disparo automático via CRON baseado em `mdl_course.startdate` — uma vez por curso
- **Camada 2 (Agendas):** Admin cria agendas de aula com data/hora e link Zoom. CRON dispara automaticamente. Admin pode enviar reforço manual (cooldown 10min)
- **Camada 3 (Conclusão):** Disparo automático via CRON baseado em `mdl_course.enddate` + delay — uma vez por curso, apenas para alunos que acessaram

### Estrutura de Diretórios

```
/notification_course/
├── bootstrap.php         ← Bootstrap do Moodle + verificação de admin + autoload
├── cron.php              ← Ponto de entrada do CRON (CLI-only, flock, --dry-run)
├── index.php             ← Dashboard (stats, próximos envios, últimos disparos)
├── schedules.php         ← CRUD de agendas + reforço manual (Camada 2)
├── settings.php          ← Configurações globais (Camadas 1, 3, batch, categorias)
├── history.php           ← Histórico com filtros e export CSV
├── test.php              ← Modo de teste (envia só para admin logado + dry-run)
├── includes/
│   ├── header.php        ← Layout HTML + navegação lateral + Tailwind CDN
│   └── footer.php        ← Fecha layout + Alpine.js + JS utilitários
├── src/
│   ├── CourseChecker.php     ← Consultas de cursos e alunos (com role student)
│   ├── ScheduleManager.php   ← CRUD das agendas + zoom suggestion + cooldown
│   ├── Mailer.php            ← Envio via email_to_user() + orquestração completa
│   ├── NotifLog.php          ← Log, dedupe, retry com backoff, stats, CSV export
│   └── TemplateEngine.php    ← Substituição de variáveis (preserva não resolvidas)
├── db/
│   ├── install.php       ← Instalador via XMLDB (MySQL + PostgreSQL)
│   └── schema.sql        ← DDL referência (apenas documentação)
├── templates/            ← Templates HTML de e-mail (inline CSS, email-safe)
└── assets/app.css        ← CSS variables shadcn/ui + componentes custom
```

### Tabelas (prefixo `notifcourse_`)

- `notifcourse_schedule` — Agendas (courseid, lesson_date, send_at, zoom_link, subject, body, status, timecreated, createdby)
- `notifcourse_log` — Log de disparos (userid, courseid, schedule_id, notification_type, origin, dedupe_key, timesent, status, attempts, next_retry_at, last_error, is_simulation)
- `notifcourse_config` — Config chave/valor (colunas: `config_key`, `config_value`, `timemodified`)
- `notifcourse_categories` — Categorias participantes (categoryid, active)

### Chaves de Configuração (notifcourse_config)

`start_subject`, `start_body`, `start_hours_before`, `end_subject`, `end_body`, `end_hours_after`, `end_survey_url`, `batch_size`, `max_attempts`, `display_timezone`

**IMPORTANTE:** As colunas são `config_key` e `config_value` (NÃO `name`/`value`). As chaves de template seguem o padrão `{type}_{field}` (ex: `start_subject`, `end_body`).

### Bootstrap e Autenticação

```php
// bootstrap.php — define NOTIFCOURSE_INTERNAL, carrega Moodle, verifica admin
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());
```

CRON: apenas CLI (`php_sapi_name() === 'cli'`), com `flock` para idempotência.

## Convenções

- Timestamps: Unix timestamp (BIGINT) em UTC. Tabela log usa `timesent`, tabela schedule usa `timecreated`
- Queries: exclusivamente via API `$DB` do Moodle (nunca PDO/mysqli)
- E-mail: exclusivamente via `email_to_user()` do Moodle
- Segurança: `require_sesskey()` em todo POST
- Deduplicação: via `dedupe_key` no log (padrão: `{origin}:{type}:{id}:{userid}`)
- Retry: backoff progressivo 1h→2h→4h, até `max_attempts`, depois `abandoned`
- Alunos: filtro por role student no contexto do curso (`contextlevel=50`, `archetype='student'`)
- Templates: placeholders `{variavel}` — se não resolvida, mantém como está

## CRON

```bash
# Produção
0 * * * * php /var/www/html/notification_course/cron.php >> /var/log/notifcourse.log 2>&1
# Simulação
php /var/www/html/notification_course/cron.php --dry-run
```

Ordem: flock → Camada 1 → Camada 2 (+ retries) → Camada 3 (+ retries) → resumo → unlock.

## Instalação do Banco

```bash
# Criar tabelas (idempotente — pula se já existem)
php /var/www/html/notification_course/db/install.php

# Recriar tabelas do zero (APAGA DADOS!)
php /var/www/html/notification_course/db/install.php --force
```

Usa XMLDB do Moodle (`xmldb_table`, `xmldb_field`, `$dbman`), compatível com MySQL/MariaDB e PostgreSQL automaticamente. O `schema.sql` é mantido apenas como referência de documentação.
