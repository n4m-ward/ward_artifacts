# Deploy (Hostinger)

Hospedagem compartilhada Premium da Hostinger, conta `u633753951`, PHP 8.4 e MySQL. O deploy é **Git
auto-deployment da Hostinger direto da `main`**: o app do GitHub da Hostinger recebe o webhook de cada push
(merge) na `main` e faz `git pull` + `composer install` na pasta do subdomínio. Não há SSH no fluxo.

```
merge na main ──► Hostinger: git pull + composer install em public_html/artifacts
       │                         │
       │                         ▼
       │          cron (a cada minuto): schedule:run → ward:post-deploy
       │          (commit novo no .git? optimize:clear, migrate --force,
       │           passport:keys na 1ª vez, seed do 1º admin com banco vazio)
       │
       └──► GitHub Actions
              ci:     testes + Pint (não bloqueia o deploy: proteja a main exigindo o CI nos PRs)
              assets: npm run build; se public/build mudou, commita na main → novo deploy
```

A Hostinger não roda npm, por isso `public/build` é versionado. Rode `npm run build` antes de abrir o PR
para o deploy já sair com o CSS/JS certo; se esquecer, o workflow `assets` corrige com um commit logo depois.

## Recursos

| Recurso | Valor |
|---|---|
| App | `https://artifacts.wardtecnology.com` → pasta `public_html/artifacts` de `wardtecnology.com` |
| Conteúdo dos artifacts | `https://artifacts-content.wardtecnology.com` → **a mesma pasta** |
| Raiz | O repositório inteiro fica na pasta; o `.htaccess` da raiz reescreve tudo para `public/` |
| Branch publicada | `main` |
| Banco | MySQL `u633753951_ward_artifacts` |
| E-mail | `no-reply@wardtecnology.com` (SMTP `smtp.hostinger.com:465`) |
| Cron | `* * * * *` → `cd <pasta> && /opt/alt/php84/usr/bin/php artisan schedule:run` |
| `.env` | Só no servidor, na raiz da pasta (modelo: `.env.production.example`) |
| Chaves OAuth | `storage/oauth-*.key`, geradas no servidor pelo `ward:post-deploy` |

Arquivos não versionados (`.env`, `vendor/`, `storage/`) sobrevivem aos pulls.

## Configuração inicial (uma vez)

Feita pela API da Hostinger (`https://developers.hostinger.com`), exceto o item 1:

1. **hPanel → Sites → wardtecnology.com → Git → conectar o GitHub** e dar acesso ao repositório
   `n4m-ward/ward_artifacts`. É a autorização OAuth do app da Hostinger no GitHub; só o dono da conta faz.
2. Subdomínios `artifacts` e `artifacts-content` apontando para a pasta `artifacts`, com SSL.
3. PHP 8.4 no subdomínio, `display_errors=Off`, limites de upload de 128 MB.
4. Banco MySQL e caixa de e-mail `no-reply@`.
5. Git auto-deployment: repositório `n4m-ward/ward_artifacts`, branch `main`, pasta raiz do subdomínio.
6. Upload do `.env` (API de arquivos, TUS) depois do primeiro clone.
7. Cron do scheduler.

## Dia a dia

- Deploy = merge/push na `main`.
- Em até ~1 minuto após o pull da Hostinger, o cron aplica as migrations.
- Para forçar o pós-deploy: crie um cron único com `php artisan ward:post-deploy --force`, ou rode
  pela API de cron jobs e leia a saída.
- Logs: `storage/logs/laravel-AAAA-MM-DD.log` (API de arquivos ou gerenciador do hPanel).

## Limites conhecidos

- O plano compartilhado limita o tamanho de requisição; uploads de artifacts muito grandes (perto de
  100 MB) dependem do limite aplicado pela Hostinger ao PHP (`upload_max_filesize`/`post_max_size`) e
  ao LiteSpeed.
- Sem fila: e-mails saem na própria requisição (`QUEUE_CONNECTION=sync`).
