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
- Esquema da reforma tributária: se a Sefin recusar com E1235 pedindo `finNFSe`, a DPS é reenviada uma vez no leiaute novo (com `finNFSe`, sem `cNBS`). Em 11/2026 a produção restrita já usa esse esquema e a produção ainda não.
- Por enquanto só Simples Nacional ME/EPP com ISS não retido. Outros casos lançam `NfseNacionalException`.
- `ambiente`: `prod` usa sefin.nfse.gov.br; qualquer outro valor usa a produção restrita.
- Link público da nota: `https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave={chave}`.

## Cancelamento

```php
$r = $service->cancelar(
    ['ambiente' => 'prod', 'certificado_conteudo' => $pfxBinario, 'certificado_senha' => $senha],
    ['chave' => $chaveAcesso, 'prestador_cpf_cnpj' => '11222333000181', 'motivo_codigo' => 1, 'motivo_texto' => 'Motivo com 15 a 255 caracteres']
);
// $r: sucesso, xml_envio, xml_retorno, mensagem
```

- `motivo_codigo`: 1 (Erro na Emissão), 2 (Serviço não Prestado) ou 9 (Outros). Envia o evento 101101 (`pedRegEvento` 1.01) assinado.
- Vale a mesma regra do `Sem resposta da Sefin:` — o cancelamento pode ter sido registrado; consulte a nota antes de tratar como erro.

Testes: `composer install && vendor/bin/phpunit`. Usam certificado autoassinado gerado na hora; nunca versione certificado real.
