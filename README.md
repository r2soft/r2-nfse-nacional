# r2soft/nfse-nacional

Emissão de NFS-e pelo padrão nacional (API Sefin Nacional): DPS 1.01 assinada (XMLDSig RSA-SHA256), enviada com mTLS usando o certificado A1 da empresa.

Tem o mesmo contrato do `r2soft/nfse-megasoft`. O consumer da fila escolhe o provedor pela configuração da empresa.

```php
$service = new \R2soft\NfseNacional\NfseNacionalService();
$r = $service->emitir(
    ['ambiente' => 'prod', 'certificado_conteudo' => $pfxBinario, 'certificado_senha' => $senha],
    $dadosRps // numero_rps, serie, competencia, valor_servicos, discriminacao, codigo_municipio,
              // item_lista_servico, numero_nbs, optante_simples_nacional, prestador_*, tomador_*
);
// $r: sucesso, numero_nfse, codigo_verificacao (chave de acesso, 50 dígitos), xml_envio, xml_retorno, mensagem
```

- `mensagem` começando por `Sem resposta da Sefin:` quer dizer que a nota pode ter saído. Antes de gravar erro, chame `consultar()` com `numero_rps`, `serie`, `codigo_municipio`, `prestador_cpf_cnpj` e `prestador_tipo`.
- Por enquanto só Simples Nacional ME/EPP com ISS não retido. Outros casos lançam `NfseNacionalException`.
- `ambiente`: `prod` usa sefin.nfse.gov.br; qualquer outro valor usa a produção restrita.
- Link público da nota: `https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave={chave}`.

Testes: `composer install && vendor/bin/phpunit`. Usam certificado autoassinado gerado na hora; nunca versione certificado real.
