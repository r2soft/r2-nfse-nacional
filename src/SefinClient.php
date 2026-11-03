<?php

namespace R2soft\NfseNacional;

/** HTTP com mTLS (certificado A1 da empresa) na API da Sefin Nacional. */
class SefinClient
{
    public const URL_PROD = 'https://sefin.nfse.gov.br/SefinNacional';
    public const URL_HOM  = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';

    /** @var string */
    private $base;
    /** @var string */
    private $pfx;
    /** @var string */
    private $senha;

    public function __construct(string $ambiente, string $pfx, string $senha, ?string $base = null)
    {
        $this->base  = rtrim($base ?? self::urlBase($ambiente), '/');
        $this->pfx   = $pfx;
        $this->senha = $senha;
    }

    public static function urlBase(string $ambiente): string
    {
        return $ambiente === 'prod' ? self::URL_PROD : self::URL_HOM;
    }

    /** @return array{0: int, 1: ?array} */
    public function enviarDps(string $xmlAssinado): array
    {
        return $this->requisitar('POST', '/nfse', json_encode(['dpsXmlGZipB64' => base64_encode(gzencode($xmlAssinado))]));
    }

    /** @return array{0: int, 1: ?array} 200 {chaveAcesso} | 404 {erro: E2404} */
    public function consultarDps(string $idDps): array
    {
        return $this->requisitar('GET', '/dps/' . rawurlencode($idDps));
    }

    /** @return array{0: int, 1: ?array} 200 {chaveAcesso, nfseXmlGZipB64} */
    public function consultarNfse(string $chave): array
    {
        return $this->requisitar('GET', '/nfse/' . rawurlencode($chave));
    }

    /** @return array{0: int, 1: ?array} 2xx {eventoXmlGZipB64} | 4xx {erro: [...]} */
    public function registrarEvento(string $chave, string $xmlAssinado): array
    {
        return $this->requisitar('POST', '/nfse/' . rawurlencode($chave) . '/eventos', json_encode(['pedidoRegistroEventoXmlGZipB64' => base64_encode(gzencode($xmlAssinado))]));
    }

    /** @return array{0: int, 1: ?array} status HTTP e corpo JSON (null se não for JSON) */
    protected function requisitar(string $metodo, string $caminho, ?string $corpo = null): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'nfse_pfx_');
        $ch      = curl_init($this->base . $caminho);
        try {
            chmod($arquivo, 0600);
            file_put_contents($arquivo, $this->pfx);
            $opcoes = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 40,
                CURLOPT_SSLCERT        => $arquivo,
                CURLOPT_SSLCERTTYPE    => 'P12',
                CURLOPT_SSLCERTPASSWD  => $this->senha,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
            ];
            if ($metodo === 'POST') {
                $opcoes[CURLOPT_POST]       = true;
                $opcoes[CURLOPT_POSTFIELDS] = (string) $corpo;
            }
            curl_setopt_array($ch, $opcoes);
            $resposta = curl_exec($ch);
            if ($resposta === false) {
                throw new SefinSemResposta(curl_error($ch) ?: 'falha de rede');
            }
            $json = json_decode((string) $resposta, true);
            return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), is_array($json) ? $json : null];
        } finally {
            curl_close($ch);
            @unlink($arquivo);
        }
    }
}
