# aut2fa-php

Use o SDK PHP da aut2fa para enviar, consultar e verificar códigos de autenticação de dois fatores pela API. O cliente usa cURL e mantém os nomes dos campos da resposta em `snake_case`.

## Requisitos

Instale PHP 8.1 ou superior com as extensões `curl` e `json`, além do [Composer](https://getcomposer.org/).

## Instalação pelo Git

Após a publicação da tag `v0.1.0` no repositório, adicione a origem Git ao projeto consumidor:

```sh
composer config repositories.aut2fa vcs https://github.com/jhowbhz/aut2fa-php
composer require aut2fa/aut2fa-php:^0.1.0
```

O pacote ainda não está cadastrado no Packagist. Quando o cadastro estiver disponível, você poderá instalar sem configurar a origem Git:

```sh
composer require aut2fa/aut2fa-php:^0.1.0
```

O Composer determina a versão pelas tags Git. O `composer.json` da biblioteca não fixa uma versão.

## Enviar e verificar um código

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

Para gerar o arquivo de distribuição, execute:

```sh
composer archive --format=zip --dir=dist --file=aut2fa-php-0.1.0
```

O arquivo exclui testes, dependências e arquivos da integração contínua. A publicação no Packagist depende do cadastro do repositório e de uma tag Git; os workflows executam somente checagens.

Consulte a [documentação da API aut2fa](https://doc.aut2fa.com) e o [histórico de alterações](CHANGELOG.md). Licença [MIT](LICENSE).
