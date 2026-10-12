<p align="center">
  <a href="https://aut2fa.com">
    <img src="https://raw.githubusercontent.com/jhowbhz/aut2fa-php/main/.github/assets/banner.png" alt="aut2fa para PHP: você envia o código, a gente entrega por WhatsApp, SMS, Telegram e e-mail" width="100%">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/aut2fa/aut2fa-php"><img src="https://img.shields.io/packagist/v/aut2fa/aut2fa-php?color=00cfc5&label=packagist" alt="Versão no Packagist"></a>
  <a href="https://packagist.org/packages/aut2fa/aut2fa-php"><img src="https://img.shields.io/packagist/dependency-v/aut2fa/aut2fa-php/php?color=777bb4&logo=php&logoColor=white" alt="Versão do PHP"></a>
  <a href="https://packagist.org/packages/aut2fa/aut2fa-php/stats"><img src="https://img.shields.io/packagist/dt/aut2fa/aut2fa-php?color=00cfc5" alt="Downloads"></a>
  <a href="https://github.com/jhowbhz/aut2fa-php/actions/workflows/ci.yml"><img src="https://github.com/jhowbhz/aut2fa-php/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <img src="https://img.shields.io/badge/pacotes%20extras-0-00cfc5" alt="Zero pacotes extras">
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/aut2fa/aut2fa-php?color=00cfc5" alt="Licença MIT"></a>
</p>

<p align="center">
  <b>Envie códigos 2FA por WhatsApp, SMS, Telegram e e-mail com uma chamada de API.</b><br>
  <a href="https://aut2fa.com">Site</a> ·
  <a href="https://doc.aut2fa.com">Documentação</a> ·
  <a href="https://packagist.org/packages/aut2fa/aut2fa-php">Packagist</a> ·
  <a href="https://aut2fa.com">Criar conta grátis</a> ·
  <a href="https://github.com/jhowbhz/aut2fa-php/issues">Reportar problema</a>
</p>

---

## Por que usar

- **Um endpoint, quatro canais.** Você chama `send`, e a aut2fa entrega por WhatsApp, SMS, Telegram ou e-mail.
- **Sem gerar nem guardar código.** A API pode gerar o código; você só chama `verify` com o que o usuário digitou.
- **Zero pacotes extras.** Usa só as extensões `curl` e `json` do PHP. Nada de Guzzle no seu `vendor`.
- **Funciona em qualquer projeto.** Laravel, Symfony, WordPress ou PHP puro: basta o autoloader do Composer.
- **Seguro por padrão.** Não segue redirects, não repete requisições sozinho e mantém a verificação TLS do cURL.
- **Erros claros.** `ApiError`, `NetworkError` e `InvalidResponseError` separam cada tipo de falha.

## Instalar

Requer PHP 8.1 ou superior com as extensões `curl` e `json`.

```sh
composer require aut2fa/aut2fa-php
```

## Começar em 30 segundos

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Aut2fa\Aut2fa;

$aut2fa = new Aut2fa(getenv('AUT2FA_API_KEY'));

// 1. Envia o código para o usuário
$send = $aut2fa->send(['to' => '+5531999999999']);

// 2. Confere o código que o usuário digitou
$result = $aut2fa->verify($send->id, ['code' => '482913']);

if ($result->valid) {
    echo 'Login liberado';
}
```

Crie sua conta em [aut2fa.com](https://aut2fa.com) para pegar a chave da aplicação.

## Enviar e verificar em detalhe

Configure `AUT2FA_API_KEY` no servidor e carregue o autoloader do Composer:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Aut2fa\Aut2fa;

$aut2fa = new Aut2fa(getenv('AUT2FA_API_KEY'));
$send = $aut2fa->send(['to' => '+5531999999999']);

$result = $aut2fa->verify($send->id, ['code' => '482913']);
if ($result->valid) {
    // Continue o fluxo de autenticação da sua aplicação.
}
```

`valid=false` é um resultado normal de verificação. Consulte `$result->verification` e `$result->attempts_left` para acompanhar as tentativas restantes.

## Consultar o envio

Use o ID retornado pelo envio para consultar o estado atual:

```php
$current = $aut2fa->status($send->id);
echo $current->status;
```

Os métodos retornam objetos `stdClass`. O envio inclui `id`, `status`, `verification`, `mode`, `channel`, `from`, `expires_at`, `created_at`, `sent_at`, `delivered_at`, `read_at`, `verified_at`, `failed_at` e `failure_reason`. O cliente preserva valores `null` e campos adicionais da API; as datas permanecem strings.

## Configurar envio e idempotência

Você pode fornecer `code` e `locale` como strings. Passe uma chave de idempotência no segundo argumento para identificar um envio lógico:

```php
$send = $aut2fa->send([
    'to' => '+5531999999999',
    'code' => '004829',
    'locale' => 'pt-BR',
], 'login-attempt-001');
```

A chave aceita até 255 bytes e rejeita caracteres de controle. O cliente não repete requisições automaticamente. Ao repetir o mesmo envio manualmente, reutilize a chave e o mesmo conteúdo para evitar outro envio.

## Configurar o cliente

O cliente usa `https://api.aut2fa.com` e timeout de 10 segundos por requisição. Para alterar a URL e o prazo, use as opções do construtor:

```php
$aut2fa = new Aut2fa(getenv('AUT2FA_API_KEY'), [
    'baseUrl' => 'http://127.0.0.1:8080',
    'timeout' => 2.5,
]);
```

`baseUrl` aceita HTTP ou HTTPS, com um prefixo de caminho opcional. A URL não pode conter credenciais, query ou fragmento. `timeout` aceita números finitos, estritamente positivos, em segundos, até 2147483.647; frações são arredondadas para cima ao milissegundo. As opções reconhecidas são `baseUrl` e `timeout`.

O cliente rejeita chaves de aplicação vazias ou com caracteres de controle. IDs aceitam somente letras ASCII, números, underscore e hífen. `to` no envio e `code` na verificação precisam ser strings não vazias. O SDK não segue redirects e mantém a verificação TLS padrão do cURL.

## Tratar erros

Capture a classe correspondente ao resultado da requisição:

| Exceção | Quando ocorre | Propriedades |
| --- | --- | --- |
| `Aut2fa\ApiError` | Resposta HTTP fora de 2xx | `status`, `code`, `detail`, `errors`, `retryAfter` |
| `Aut2fa\NetworkError` | Falha de conexão, TLS ou timeout | `curlCode`, `timedOut` |
| `Aut2fa\InvalidResponseError` | Resposta 2xx com JSON inválido ou sem os campos centrais | `status` |
| `InvalidArgumentException` | Credenciais, opções ou parâmetros inválidos | Mensagem de validação |

`ApiError->code` contém o código estável da API, como `rate_limited` ou `invalid_recipient`. `status` contém o status HTTP real. `errors` preserva os campos de validação como objeto, quando presentes, e `retryAfter` mantém o valor do cabeçalho `Retry-After` como string ou `null`.

Corpos de erro não JSON ou fora do envelope esperado resultam em `code=http_error`, sem incluir o corpo na mensagem. Respostas de envio e consulta precisam ter `id` e `status` como strings não vazias; verificação exige `valid` booleano, `verification` string não vazia e `attempts_left` inteiro não negativo. Campos adicionais continuam disponíveis.

Uma falha de rede pode ocorrer após a API receber a requisição. Use a chave de idempotência ao repetir um envio; avalie as tentativas restantes antes de repetir uma verificação.

## Desenvolver e empacotar

Na raiz do repositório, instale as dependências de desenvolvimento e execute as checagens:

```sh
composer install
composer validate --strict --no-check-lock
composer lint
composer test
```

Os testes usam um servidor HTTP local real e não chamam a API aut2fa. Eles precisam de `proc_open` e acesso ao loopback. A integração contínua executa as checagens em PHP 8.1 a 8.5 no Linux e PHP 8.5 no Windows.

## Publicar novas versões

O pacote está no [Packagist](https://packagist.org/packages/aut2fa/aut2fa-php) e é atualizado automaticamente. Para lançar uma versão, crie e envie uma tag semântica, como `v0.1.1`; o Composer lê a versão pela tag.

## Outras linguagens

| Linguagem | Pacote | Repositório |
| --- | --- | --- |
| PHP | [`aut2fa/aut2fa-php`](https://packagist.org/packages/aut2fa/aut2fa-php) | [aut2fa-php](https://github.com/jhowbhz/aut2fa-php) |
| Node.js | [`@aut2fa/node`](https://www.npmjs.com/package/@aut2fa/node) | [aut2fa-node](https://github.com/jhowbhz/aut2fa-node) |
| Python | [`aut2fa`](https://pypi.org/project/aut2fa/) | [aut2fa-python](https://github.com/jhowbhz/aut2fa-python) |
| Go | [`github.com/jhowbhz/aut2fa-go`](https://pkg.go.dev/github.com/jhowbhz/aut2fa-go) | [aut2fa-go](https://github.com/jhowbhz/aut2fa-go) |

## Contribuir

Encontrou um bug ou tem uma ideia? Abra uma [issue](https://github.com/jhowbhz/aut2fa-php/issues) ou envie um pull request. Consulte o [histórico de alterações](CHANGELOG.md) para acompanhar as versões.

Se este SDK te ajudou, deixe uma ⭐ no repositório. Isso ajuda outros devs a encontrá-lo.

---

<p align="center">
  <a href="https://doc.aut2fa.com">Documentação da API</a> · Licença <a href="LICENSE">MIT</a> · Feito pela <a href="https://aut2fa.com">aut2fa</a>
</p>
