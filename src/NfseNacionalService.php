<?php

namespace R2soft\NfseNacional;

/**
 * Emissão e consulta de NFS-e na Sefin Nacional com o mesmo contrato do
 * R2soft\MegasoftNfse\MegasoftNfseService, para o consumer trocar de provedor sem mudar o fluxo.
 *
 * Recusa da Sefin (4xx) é conclusiva. Sem resposta (rede, timeout, 5xx) devolve a mensagem
 * começando por SEM_RESPOSTA: o consumer então consulta a DPS antes de gravar erro.
 */
class NfseNacionalService
{
    public const SEM_RESPOSTA = 'Sem resposta da Sefin: ';

    /** @var callable */
    private $fabricaCliente;
    /** @var callable */
    private $relogio;

    public function __construct(?callable $fabricaCliente = null, ?callable $relogio = null)
    {
        $this->fabricaCliente = $fabricaCliente ?? static function (string $ambiente, string $pfx, string $senha): SefinClient {
            return new SefinClient($ambiente, $pfx, $senha);
        };
        $this->relogio = $relogio ?? static function (): \DateTimeImmutable {
            return new \DateTimeImmutable('now');
        };
    }

    public function emitir(array $config, array $dados): array
    {
        $ambiente = (string) ($config['ambiente'] ?? 'hom');
        [$pfx, $senha] = self::certificado($config);
        $xmlEnvio = (new AssinadorXml($pfx, $senha))->assinar(Dps::xml($dados, $ambiente, ($this->relogio)()));
        $cliente  = ($this->fabricaCliente)($ambiente, $pfx, $senha);

        try {
            [$status, $json] = $cliente->enviarDps($xmlEnvio);
        } catch (SefinSemResposta $e) {
            return self::falha($xmlEnvio, self::SEM_RESPOSTA . $e->getMessage());
        }

        if ($status >= 200 && $status < 300 && !empty($json['chaveAcesso'])) {
            $chave = (string) $json['chaveAcesso'];
            $xml   = self::xmlNfse($json) ?? self::buscarXml($cliente, $chave);
            return [
                'sucesso'            => true,
                'numero_nfse'        => $xml !== null ? self::numeroNfse($xml) : null,
                'codigo_verificacao' => $chave,
                'xml_envio'          => $xmlEnvio,
                'xml_retorno'        => $xml ?? json_encode($json),
                'mensagem'           => null,
            ];
        }
        if ($status >= 400 && $status < 500) {
            $erros = $json['erros'] ?? $json['Erros'] ?? null;
            return self::falha(
                $xmlEnvio,
                $erros ? self::mensagemErros($erros) : "Sefin recusou a requisição: HTTP {$status}",
                $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE) : ''
            );
        }
        return self::falha($xmlEnvio, self::SEM_RESPOSTA . "HTTP {$status}");
    }

    public function consultar(array $config, array $dados): array
    {
        $ambiente = (string) ($config['ambiente'] ?? 'hom');
        [$pfx, $senha] = self::certificado($config);
        $cliente = ($this->fabricaCliente)($ambiente, $pfx, $senha);
        $idDps   = Dps::id(
            (string) ($dados['codigo_municipio'] ?? ''),
            (string) ($dados['prestador_tipo'] ?? 'CNPJ'),
            (string) ($dados['prestador_cpf_cnpj'] ?? ''),
            preg_replace('/\D/', '', (string) ($dados['serie'] ?? '')) ?: Dps::SERIE_PADRAO,
            (string) ($dados['numero_rps'] ?? '')
        );

        try {
            [$status, $json] = $cliente->consultarDps($idDps);
        } catch (SefinSemResposta $e) {
            return self::consulta(null, null, null, null, self::SEM_RESPOSTA . $e->getMessage());
        }
        if ($status === 404) {
            return self::consulta(false, null, null, null, "DPS {$idDps} não virou NFS-e na Sefin.");
        }
        if ($status !== 200 || empty($json['chaveAcesso'])) {
            return self::consulta(null, null, null, null, "Consulta da DPS {$idDps}: HTTP {$status}");
        }
        $chave = (string) $json['chaveAcesso'];
        $xml   = self::buscarXml($cliente, $chave);
        return self::consulta(true, $xml !== null ? self::numeroNfse($xml) : null, $chave, $xml, null);
    }

    /** @return array{0: string, 1: string} */
    private static function certificado(array $config): array
    {
        $pfx = (string) ($config['certificado_conteudo'] ?? '');
        if ($pfx === '') {
            throw new NfseNacionalException('Certificado A1 não configurado para a empresa.');
        }
        return [$pfx, (string) ($config['certificado_senha'] ?? '')];
    }

    private static function buscarXml(SefinClient $cliente, string $chave): ?string
    {
        try {
            [$status, $json] = $cliente->consultarNfse($chave);
        } catch (SefinSemResposta $e) {
            return null;
        }
        return $status === 200 && is_array($json) ? self::xmlNfse($json) : null;
    }

    private static function xmlNfse(?array $json): ?string
    {
        if (empty($json['nfseXmlGZipB64'])) {
            return null;
        }
        $xml = @gzdecode((string) base64_decode((string) $json['nfseXmlGZipB64'], true));
        return $xml === false || $xml === '' ? null : $xml;
    }

    private static function numeroNfse(string $xml): ?string
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return null;
        }
        $no = $doc->getElementsByTagNameNS(Dps::NS, 'nNFSe')->item(0);
        return $no ? trim($no->textContent) : null;
    }

    private static function mensagemErros(array $erros): string
    {
        $partes = [];
        foreach ($erros as $e) {
            $codigo      = $e['Codigo'] ?? $e['codigo'] ?? '';
            $descricao   = $e['Descricao'] ?? $e['descricao'] ?? '';
            $complemento = $e['Complemento'] ?? $e['complemento'] ?? '';
            $partes[]    = trim("{$codigo} – {$descricao}") . ($complemento !== '' ? " ({$complemento})" : '');
        }
        return implode('; ', $partes);
    }

    private static function falha(string $xmlEnvio, string $mensagem, string $xmlRetorno = ''): array
    {
        return [
            'sucesso'            => false,
            'numero_nfse'        => null,
            'codigo_verificacao' => null,
            'xml_envio'          => $xmlEnvio,
            'xml_retorno'        => $xmlRetorno,
            'mensagem'           => $mensagem,
        ];
    }

    private static function consulta(?bool $encontrada, ?string $numero, ?string $chave, ?string $xml, ?string $mensagem): array
    {
        return [
            'sucesso'            => $encontrada === true,
            'encontrada'         => $encontrada,
            'cancelada'          => false,
            'numero_nfse'        => $numero,
            'codigo_verificacao' => $chave,
            'xml_retorno'        => $xml ?? '',
            'mensagem'           => $mensagem,
        ];
    }
}
