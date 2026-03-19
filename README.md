# Notification Course - Moodle

Sistema de notificações automáticas de cursos para Moodle. Envia e-mails para alunos matriculados com base em agendamentos configuráveis, integrado ao ambiente Moodle existente.

## Funcionalidades

- **Notificações de Início de Curso** — disparo automático antes da data de início
- **Agendas de Aula** — CRUD completo com suporte a agendamento único e recorrente
- **Notificações de Conclusão** — envio automático após o encerramento do curso
- **Retries com backoff progressivo** — reenvio automático de falhas (1h, 2h, 4h)
- **Deduplicação** — evita envios duplicados por chave única
- **Dashboard** — visão geral com estatísticas, próximos envios e últimos disparos
- **Histórico** — log completo de todos os envios com filtros e exportação CSV
- **Configurações** — templates personalizáveis por camada, categorias participantes e parâmetros gerais

## Arquitetura

O sistema opera em 4 camadas executadas pelo CRON:

| Camada | Responsabilidade |
|--------|-----------------|
| 1 | Garantir agendas de início para cursos próximos |
| 2 | Processar agendas pendentes (start, lesson, end) |
| 3 | Garantir agendas de fim para cursos concluídos |
| 4 | Retries unificados de envios falhos |

## Estrutura do Projeto

```
notification_course/
├── index.php              # Dashboard
├── schedules.php          # CRUD de agendas
├── settings.php           # Configurações
├── history.php            # Histórico de envios
├── cron.php               # Ponto de entrada do CRON (CLI)
├── bootstrap.php          # Bootstrap do Moodle + auth + autoloader
├── assets/
│   └── app.css            # Estilos da aplicação
├── db/
│   ├── schema.sql         # DDL das tabelas (referência)
│   └── install.php        # Instalador XMLDB + migrations
├── includes/
│   ├── header.php         # Layout: header + sidebar
│   └── footer.php         # Layout: footer + scripts
├── src/
│   ├── CourseChecker.php  # Consultas de cursos e alunos matriculados
│   ├── Mailer.php         # Envio de e-mails + orquestração
│   ├── NotifLog.php       # Log, deduplicação, retries e histórico
│   ├── ScheduleManager.php# CRUD de agendas + sugestão de link
│   └── TemplateEngine.php # Renderização de templates com placeholders
└── templates/
    ├── email_start.html   # Template de início de curso
    ├── email_lesson.html  # Template de lembrete de aula
    └── email_end.html     # Template de conclusão de curso
```

## Tabelas do Banco de Dados

| Tabela | Descrição |
|--------|-----------|
| `notifcourse_schedule` | Agendas de aula (courseid, lesson_date, send_at, link_aula, subject, body, status) |
| `notifcourse_log` | Log de todos os envios com deduplicação, retries e status |
| `notifcourse_config` | Configurações key/value (templates, parâmetros, timezone) |
| `notifcourse_categories` | Categorias de curso participantes do sistema |

## Variáveis de Template

Disponíveis nos templates de e-mail:

| Variável | Descrição |
|----------|-----------|
| `{nome_aluno}` | Nome completo do aluno |
| `{nome_curso}` | Nome do curso |
| `{login_moodle}` | Username do aluno |
| `{link_esqueci_senha}` | Link para redefinição de senha |
| `{data_inicio}` | Data de início do curso |
| `{data_termino}` | Data de encerramento do curso |
| `{data_aula}` | Data da aula agendada |
| `{hora_aula}` | Horário da aula |
| `{link_aula}` | Link de acesso (qualquer URL) |
| `{link_pesquisa}` | Link da pesquisa de satisfação |
| `{logo_url}` | URL do logo configurado |

## Instalação

### 1. Copiar para o Moodle

Copie a pasta `notification_course` para a raiz do Moodle:

```bash
cp -r notification_course/ /caminho/para/moodle/notification_course/
```

### 2. Instalar tabelas

**Via CLI:**
```bash
php notification_course/db/install.php
```

**Via navegador:**
Acesse `https://seu-moodle.com/notification_course/db/install.php` e clique em "Instalar Tabelas".

### 3. Configurar o CRON

Adicione ao crontab:

```bash
# Executa a cada 5 minutos
*/5 * * * * /usr/bin/php /caminho/para/moodle/notification_course/cron.php >> /var/log/notifcourse.log 2>&1
```

### 4. Configurar categorias e templates

Acesse `https://seu-moodle.com/notification_course/settings.php` para:
- Selecionar as categorias de curso participantes
- Personalizar os templates de e-mail de início e conclusão
- Configurar URL do logo, tamanho de lote e tentativas máximas

## Uso

### Agendas

Em **Agendas** (`schedules.php`):
- Crie agendamentos únicos ou recorrentes para aulas
- O **Link do Curso** é preenchido automaticamente (editável para qualquer URL)
- Agendas de início e fim são criadas automaticamente pelo CRON

### CRON

```bash
# Execução normal
php cron.php

# Simulação (sem envio real)
php cron.php --dry-run
```

## Requisitos

- Moodle 4.x
- PHP 8.1+
- MySQL/MariaDB ou PostgreSQL

## Regras de Envio

- **Destinatários:** apenas alunos com inscrição **ativa** no curso (exclui suspensos; "nunca acessou" recebe normalmente)
- **Deduplicação:** cada agenda tem chave única por aluno — recriar uma agenda gera novo ID e permite reenvio
- **Retries:** falhas são reenviadas com backoff progressivo (1h, 2h, 4h) até o limite configurado
- **Cooldown manual:** reforços manuais respeitam intervalo mínimo de 10 minutos
