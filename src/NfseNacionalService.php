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
        $assinador = new AssinadorXml($pfx, $senha);
        $agora     = ($this->relogio)();
        $xmlEnvio  = $assinador->assinar(Dps::xml($dados, $ambiente, $agora));
        $cliente   = ($this->fabricaCliente)($ambiente, $pfx, $senha);

        try {
            [$status, $json] = $cliente->enviarDps($xmlEnvio);
            // Sefin já no esquema da reforma tributária: a DPS foi recusada (não virou nota),
            // então reenviar uma vez, com o mesmo Id, no leiaute novo.
            if (self::pedeLeiauteRtc($status, $json)) {
                $xmlEnvio = $assinador->assinar(Dps::xml($dados, $ambiente, $agora, true));
                [$status, $json] = $cliente->enviarDps($xmlEnvio);
            }
        } catch (SefinSemResposta $e) {
            return self::falha($xmlEnvio, self::SEM_RESPOSTA . $e->getMessage());
        }

        if ($status >= 200 && $status < 300 && !empty($json['chaveAcesso'])) {
            $chave = (string) $json['chaveAcesso'];
            $xml   = self::xmlDaResposta($json) ?? self::buscarXml($cliente, $chave);
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
            $erros = self::errosDe($json);
            return self::falha(
                $xmlEnvio,
                $erros ? self::mensagemErros($erros) : "Sefin recusou a requisição: HTTP {$status}",
                $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE) : ''
            );
        }
        return self::falha($xmlEnvio, self::SEM_RESPOSTA . "HTTP {$status}");
    }

    public function cancelar(array $config, array $dados): array
    {
        $ambiente = (string) ($config['ambiente'] ?? 'hom');
        [$pfx, $senha] = self::certificado($config);
        $agora    = ($this->relogio)();
        $chave    = preg_replace('/\D/', '', (string) ($dados['chave'] ?? ''));
        $xml      = PedidoCancelamento::xml(
            $chave,
            (string) ($dados['prestador_cpf_cnpj'] ?? ''),
            (int) ($dados['motivo_codigo'] ?? 0),
            (string) ($dados['motivo_texto'] ?? ''),
            $ambiente,
            $agora
        );
        $assinado = (new AssinadorXml($pfx, $senha))->assinar($xml, 'infPedReg');
        $cliente  = ($this->fabricaCliente)($ambiente, $pfx, $senha);

        try {
            [$status, $json] = $cliente->registrarEvento($chave, $assinado);
        } catch (SefinSemResposta $e) {
            return self::cancelamento(false, $assinado, '', self::SEM_RESPOSTA . $e->getMessage());
        }

        if ($status >= 200 && $status < 300) {
            $xmlEvento = self::xmlEvento($json);
            return self::cancelamento(
                true,
                $assinado,
                $xmlEvento !== null ? $xmlEvento : (string) json_encode($json, JSON_UNESCAPED_UNICODE),
                null
            );
        }
        if ($status >= 400 && $status < 500) {
            $erros = self::errosDe($json);
            return self::cancelamento(
                false,
                $assinado,
                $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE) : '',
                $erros ? self::mensagemErros($erros) : "Sefin recusou o cancelamento: HTTP {$status}"
            );
        }
        return self::cancelamento(false, $assinado, '', self::SEM_RESPOSTA . "HTTP {$status}");
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

    /**
     * XML assinado da NFS-e pela chave de acesso (GET /nfse/{chave} na Sefin). Serve para gerar o
     * DANFSe de nota cancelada, cujo XML guardado foi trocado pelo do evento de cancelamento.
     *
     * @return string|null o XML, ou null quando a Sefin não devolve a nota ou não responde
     */
    public function xmlNfse(array $config, string $chave): ?string
    {
        [$pfx, $senha] = self::certificado($config);
        $cliente = ($this->fabricaCliente)((string) ($config['ambiente'] ?? 'hom'), $pfx, $senha);
        return self::buscarXml($cliente, $chave);
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
        return $status === 200 && is_array($json) ? self::xmlDaResposta($json) : null;
    }

    private static function xmlDaResposta(?array $json): ?string
    {
        return self::xmlGzip($json['nfseXmlGZipB64'] ?? null);
    }

    private static function xmlEvento(?array $json): ?string
    {
        return self::xmlGzip($json['eventoXmlGZipB64'] ?? null);
    }

    private static function xmlGzip(?string $b64): ?string
    {
        if ($b64 === null || $b64 === '') {
            return null;
        }
        $xml = @gzdecode((string) base64_decode($b64, true));
        return $xml === false || $xml === '' ? null : $xml;
    }

    /**
     * Erros de recusa da Sefin: aceita 'erros', 'Erros' e 'erro' (singular),
     * que pode ser uma lista de erros ou um único objeto. Devolve sempre lista ou null.
     */
    private static function errosDe(?array $json): ?array
    {
        foreach (['erros', 'Erros', 'erro'] as $campo) {
            if (isset($json[$campo]) && is_array($json[$campo]) && $json[$campo] !== []) {
                return isset($json[$campo][0]) ? $json[$campo] : [$json[$campo]];
            }
        }
        return null;
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

    /** E1235 (esquema) apontando finNFSe: a Sefin já valida pelo leiaute da reforma tributária. */
    private static function pedeLeiauteRtc(int $status, ?array $json): bool
    {
        if ($status !== 400) {
            return false;
        }
        foreach ($json['erros'] ?? $json['Erros'] ?? [] as $e) {
            $codigo = $e['Codigo'] ?? $e['codigo'] ?? '';
            $texto  = ($e['Complemento'] ?? $e['complemento'] ?? '') . ' ' . ($e['Descricao'] ?? $e['descricao'] ?? '');
            if ($codigo === 'E1235' && strpos($texto, 'finNFSe') !== false) {
                return true;
            }
        }
        return false;
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

    private static function cancelamento(bool $sucesso, string $xmlEnvio, string $xmlRetorno, ?string $mensagem): array
    {
        return [
            'sucesso'     => $sucesso,
            'xml_envio'   => $xmlEnvio,
            'xml_retorno' => $xmlRetorno,
            'mensagem'    => $mensagem,
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
