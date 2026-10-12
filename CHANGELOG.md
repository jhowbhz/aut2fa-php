# Changelog

## 0.1.0

Primeira versão do cliente PHP da API aut2fa:

- Envio de códigos com `send`, consulta com `status` e verificação com `verify`
- Cliente HTTP cURL para PHP 8.1 ou superior, com URL base e timeout configuráveis
- Respostas como objetos, preservando valores `null` e campos adicionais
- Erros HTTP, de rede e de resposta inválida identificáveis
- Chave de idempotência opcional, validação local, sem redirects e sem retentativas automáticas
- Testes de contrato com servidor HTTP local e integração contínua para Composer, sintaxe PHP e testes
- Instalação por Git após a tag e instruções para futura distribuição no Packagist
