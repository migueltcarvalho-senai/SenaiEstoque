# Regras de Segurança e Integridade — Estoque 2D SENAI

O servidor (PHP + MySQL) é a única fonte da verdade. O navegador apenas exibe o estado e pede operações; **toda regra abaixo é aplicada no servidor**, mesmo que alguém altere o JavaScript ou chame a API diretamente.

## 1. Acesso

| Regra | Onde |
|---|---|
| Toda ação da API, exceto `health`, exige sessão válida (senão HTTP 401). | `api.php` |
| Toda escrita exige `POST` + cabeçalho `X-CSRF-Token` da sessão (senão 403). | `api.php`, `auth.php` |
| Usuário com senha temporária só pode trocar a senha. | `api.php` |
| Sessão expira após 8 horas sem uso. Usuário desativado perde o acesso na próxima requisição. | `auth.php` |
| 5 senhas erradas seguidas bloqueiam a conta por 15 minutos. | `auth.php` |
| Senha: mínimo de 8 caracteres, com letras e números, guardada apenas como hash (`password_hash`). | `auth.php` |

## 2. Perfis

| Ação | Operador | Admin |
|---|:---:|:---:|
| Consultar estoque, histórico e exportar | ✔ | ✔ |
| Cadastrar/editar produto, entrada, saída, transferência, validade, ajuste ±1 | ✔ | ✔ |
| Importar planilha no modo **mesclar** | ✔ | ✔ |
| Importar planilha no modo **substituir** | ✘ | ✔ |
| Excluir produto | ✘ | ✔ |
| Criar/remover posição extra, esvaziar o mapa inteiro | ✘ | ✔ |
| Restaurar backup, limpar histórico, zerar o banco | ✘ | ✔ |
| Gerenciar usuários | ✘ | ✔ |

Proteções adicionais: o admin não pode remover o próprio acesso de administrador, e o sistema sempre mantém pelo menos um admin ativo.

## 3. Integridade do estoque

- **Quantidades** são inteiros entre 1 e 1.000.000. Nenhum saldo fica negativo.
- **Baixa e transferência** nunca podem ultrapassar o saldo da posição de origem (HTTP 422 com o saldo atual).
- **SKU único**: validado na API (HTTP 409 indicando o produto que já usa o SKU) e por índice `UNIQUE` no banco.
- **Estoque máximo** precisa ser maior ou igual ao mínimo.
- **Datas** devem ser válidas, no formato `AAAA-MM-DD`.
- **Posição ocupada por outro produto**: só é substituída com confirmação explícita (`displace`). O item anterior vai para o Estoque Geral, nunca é perdido.
- **Remover uma posição extra** com saldo move o saldo para o Estoque Geral.
- **Concorrência**: todas as escritas são serializadas por uma trava (`GET_LOCK`) e rodam em transação. Se outro usuário alterou os dados, a operação falha com 404/409 e a tela é recarregada com a situação real.
- **Importação e backup** são validados por inteiro antes de gravar qualquer coisa. Um erro indica a linha ou item problemático e nada é alterado.
- **Autoria**: toda operação gera um registro no histórico com o usuário da sessão, preenchido pelo servidor.

## 4. Configuração e arquivos

- As credenciais ficam em `.env` (fora do Git). O modelo está em `.env.example`.
- `.htaccess` (Apache) e `router.php` (`php -S`) bloqueiam o acesso a `.env`, `.sql`, `.md`, `.txt`, `.ps1`, `.json` e aos arquivos PHP que são apenas incluídos (`auth.php`, `config.php`, `estoque.php`, `schema.php`).
- Em produção (InfinityFree), o `.env` é gerado pelo GitHub Actions a partir dos Secrets do repositório. As senhas nunca são commitadas (ver `DEPLOY.md`).
- Erros internos são registrados no log do PHP. O usuário vê só uma mensagem genérica.
- As bibliotecas externas têm versão fixa. Lucide e SheetJS são verificadas por hash (SRI).
