# Deploy (Hostinger)

Hospedagem compartilhada Premium da Hostinger, conta `u633753951`, PHP 8.3 (herdado do wardtecnology.com) e MySQL. O deploy é **Git
auto-deployment da Hostinger acompanhando a branch `deploy`**. Não há SSH no fluxo.

```
merge na main ──► GitHub Actions (deploy.yml)
                    1. CI: testes + Pint
                    2. se passou: branch deploy = código da main + public/build compilado
                          │ push na deploy (webhook do app da Hostinger)
                          ▼
       Hostinger: git pull + composer install em public_html/artifacts
                          │
                          ▼
       cron (a cada minuto): schedule:run → ward:post-deploy
       (commit novo no .git? optimize:clear, migrate --force,
        passport:keys na 1ª vez, seed do 1º admin com banco vazio)
```

A `main` fica limpa (sem `public/build`). A Hostinger não roda npm, então os assets vão compilados só
na branch `deploy`, que é gerada pelo workflow e **não deve ser editada à mão**. Se o CI falhar, nada é
publicado.

## Recursos (como está configurado)

| Recurso | Valor |
|---|---|
| App | `https://artifacts.wardtecnology.com`: subdomínio do `wardtecnology.com`, pasta `~/domains/wardtecnology.com/public_html/artifacts`, atrás da CDN da Hostinger |
| Conteúdo dos artifacts | `https://artifacts-content.wardtecnology.com`: outro subdomínio apontando para **a mesma pasta** (não dá para usar domínio estacionado em subdomínio) |
| Raiz | O repositório inteiro fica na pasta; o `.htaccess` da raiz reescreve tudo para `public/` |
| Auto-deploy | App do GitHub da Hostinger no site `artifacts.wardtecnology.com`: repositório `n4m-ward/ward_artifacts`, branch `deploy`, pasta raiz |
| PHP | 8.3, herdado do `wardtecnology.com` (o Composer está fixado em `platform.php = 8.3.35`). A extensão `sodium` foi ligada (exigida pelo `lcobucci/jwt` do Passport) |
| PHP na linha de comando | Use `/opt/alt/php83/usr/bin/php` (o `php` padrão do SSH é 8.2) |
| Banco | MySQL `u633753951_ward_artifacts` (usuário de mesmo nome) |
| Cache / sessão / fila | Arquivo / banco / `sync` |
| E-mail | `no-reply@wardtecnology.com` (SMTP `smtp.hostinger.com:465`) |
| Cron | `* * * * *` → `/opt/alt/php83/usr/bin/php /home/u633753951/domains/wardtecnology.com/public_html/artifacts/artisan schedule:run` |
| `.env` | Só no servidor, na raiz da pasta, permissão 600. A cópia local fica em `.env.production` (fora do git) |
| Chaves OAuth | `storage/oauth-*.key`, geradas no servidor pelo `ward:post-deploy`, permissão 600 |

Arquivos não versionados (`.env`, `vendor/`, `storage/`) sobrevivem aos pulls.

## Configuração inicial (feita)

1. hPanel: GitHub conectado e auto-deploy da branch `deploy` no site `artifacts.wardtecnology.com`.
2. API da Hostinger: subdomínios, SSL (automático), `display_errors`/`expose_php` desligados e `sodium`
   ligado (essas opções valem para todo o `wardtecnology.com`), banco, caixa `no-reply@` e cron.
3. `.env` enviado por SSH (`scp`) e `chmod 600`.

Atenção: as opções e extensões de PHP de um subdomínio são as do `wardtecnology.com` inteiro; mudar
versão de PHP afeta também o site principal e o `novel-kindle-parser`.

## Dia a dia

- Deploy = merge/push na `main`. Acompanhe em GitHub → Actions → `deploy`.
- Em até ~1 minuto após o pull da Hostinger, o cron aplica as migrations.
- Para forçar o pós-deploy: `/opt/alt/php83/usr/bin/php artisan ward:post-deploy --force` na pasta.
- Logs: `storage/logs/laravel-AAAA-MM-DD.log` (API de arquivos ou gerenciador do hPanel).

## Limites conhecidos

- O plano compartilhado limita o tamanho de requisição; uploads de artifacts muito grandes (perto de
  100 MB) dependem do limite aplicado pela Hostinger ao PHP (`upload_max_filesize`/`post_max_size`) e
  ao LiteSpeed.
- Sem fila: e-mails saem na própria requisição (`QUEUE_CONNECTION=sync`).
