# Deploy na InfinityFree (GitHub → FTP automático)

Cada `push` na branch `main` publica o site sozinho, pelo workflow `.github/workflows/deploy.yml`.

## Configuração única

### 1. Na InfinityFree (painel da conta)

1. Em **MySQL Databases**, crie um banco (ex.: `estoque`). Anote o **MySQL Host Name**, o **Database Name** completo (`if0_..._estoque`) e o **Username** (`if0_...`). A senha é a da conta de hospedagem.
2. Em **FTP Details**, anote o **FTP Hostname** (`ftpupload.net`), o **FTP Username** e o **FTP Password**.

Não é preciso importar nenhum SQL: o sistema cria as tabelas sozinho no primeiro acesso.

### 2. No GitHub

Em **Settings › Secrets and variables › Actions › New repository secret**, crie:

| Secret | Valor |
|---|---|
| `FTP_SERVER` | FTP Hostname (ex.: `ftpupload.net`) |
| `FTP_USERNAME` | FTP Username (ex.: `if0_12345678`) |
| `FTP_PASSWORD` | FTP Password |
| `DB_HOST` | MySQL Host Name (ex.: `sql123.infinityfree.com`) |
| `DB_NAME` | Database Name (ex.: `if0_12345678_estoque`) |
| `DB_USER` | MySQL Username (ex.: `if0_12345678`) |
| `DB_PASS` | MySQL Password |

### 3. Publicar

1. Faça `git push` na `main`, ou vá em **Actions › Deploy via FTP › Run workflow**.
2. Acompanhe na aba **Actions**. O primeiro envio demora alguns minutos; os seguintes enviam só os arquivos alterados.
3. Abra o site **imediatamente** e crie o administrador na tela de configuração inicial. Até que isso seja feito, quem acessar primeiro vira administrador.

## O que é enviado

Vão para `htdocs/` apenas os arquivos do site, mais o `.env` gerado a partir dos Secrets. Ficam fora do envio a documentação (`*.md`, `*.txt`), `database.sql`, `router.php`, `.github/` e as ferramentas locais.

## Problemas comuns

- **O Action falha com "Secret ... não configurado"**: falta criar o Secret indicado.
- **Tela "Banco de dados indisponível"**: confira os Secrets `DB_*` (o host não é `localhost`) e rode o workflow de novo.
- **Página de erro da InfinityFree depois do deploy**: renomeie o `.htaccess` pelo gerenciador de arquivos para testar. Se o site voltar, avise quem mantém o projeto.
- **Dados de exemplo**: o `database.sql`, com os produtos de demonstração, fica fora do repositório (está no `.gitignore`). Quem tiver o arquivo pode importá-lo pelo phpMyAdmin da InfinityFree, com o banco selecionado.
