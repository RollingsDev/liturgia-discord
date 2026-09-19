# Liturgia do Dia → Discord

Automação simples em PHP para publicar diariamente a Liturgia do Dia em um canal do Discord por Webhook.

## Como funciona

1. O GitHub Actions executa todos os dias às **06:05 (America/Sao_Paulo)**.
2. O script consulta a API pública `https://liturgia.up.railway.app/v2/`.
3. A liturgia é formatada em mensagens compatíveis com os limites do Discord.
4. O conteúdo é enviado ao webhook configurado no secret `DISCORD_WEBHOOK_URL`.
5. A data da última publicação bem-sucedida é registrada em `.state/last-posted-date.txt` para evitar duplicações.

## Configuração obrigatória

No repositório, abra:

**Settings → Secrets and variables → Actions → New repository secret**

Crie:

- **Name:** `DISCORD_WEBHOOK_URL`
- **Secret:** a URL completa do webhook do Discord

Nunca coloque a URL do webhook diretamente no código ou em um commit.

## Teste manual

Depois de criar o secret:

**Actions → Publicar Liturgia do Dia → Run workflow**

Por padrão, o script não repete uma liturgia já publicada no mesmo dia. Para testar novamente, marque a opção **force** ao executar manualmente.

## Arquivos

- `liturgia.php`: busca, formata e envia a liturgia.
- `.github/workflows/liturgia.yml`: agenda e executa a automação.
- `.state/last-posted-date.txt`: criado automaticamente após a primeira publicação bem-sucedida.

## Fonte dos dados

Os dados litúrgicos vêm do projeto comunitário **Liturgia Diária**, versão v2 estável:

`https://liturgia.up.railway.app/v2/`

Se a API retornar uma data diferente da data atual em `America/Sao_Paulo`, o script falha em vez de publicar uma liturgia potencialmente incorreta.
