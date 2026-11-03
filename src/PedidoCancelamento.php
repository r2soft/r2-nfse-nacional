<?php

namespace R2soft\NfseNacional;

/**
 * pedRegEvento 1.01 do evento 101101 (cancelamento de NFS-e), leiaute do Anexo II SEFIN/ADN,
 * validado na produção restrita da Sefin em 11/2026.
 */
final class PedidoCancelamento
{
    public const CODIGO_EVENTO = '101101';
    public const MOTIVOS = [1 => 'Erro na Emissão', 2 => 'Serviço não Prestado', 9 => 'Outros'];

    public static function xml(string $chave, string $cpfCnpjAutor, int $cMotivo, string $xMotivo, string $ambiente, \DateTimeImmutable $agora): string
    {
        $chave = preg_replace('/\D/', '', $chave);
        if (strlen($chave) !== 50) {
            throw new NfseNacionalException('Chave de acesso da NFS-e deve ter 50 dígitos.');
        }
        $docAutor = preg_replace('/\D/', '', $cpfCnpjAutor);
        $tagAutor = strlen($docAutor) === 11 ? 'CPFAutor' : (strlen($docAutor) === 14 ? 'CNPJAutor' : '');
        if ($tagAutor === '') {
            throw new NfseNacionalException('Documento do autor do cancelamento deve ter 14 dígitos (CNPJ) ou 11 dígitos (CPF).');
        }
        if (!isset(self::MOTIVOS[$cMotivo])) {
            throw new NfseNacionalException('Código do motivo do cancelamento deve ser 1, 2 ou 9.');
        }
        $motivo = (string) $xMotivo;
        if (!mb_check_encoding($motivo, 'UTF-8')) {
            $motivo = mb_convert_encoding($motivo, 'UTF-8', 'ISO-8859-1');
        }
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo));
        if (mb_strlen($motivo, 'UTF-8') < 15 || mb_strlen($motivo, 'UTF-8') > 255) {
            throw new NfseNacionalException('O motivo do cancelamento deve ter de 15 a 255 caracteres.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $ped = $doc->appendChild($doc->createElementNS(Dps::NS, 'pedRegEvento'));
        $ped->setAttribute('versao', '1.01');
        $inf = self::el($ped, 'infPedReg');
        $inf->setAttribute('Id', 'PRE' . $chave . self::CODIGO_EVENTO);

        self::el($inf, 'tpAmb', $ambiente === 'prod' ? '1' : '2');
        self::el($inf, 'verAplic', Dps::VER_APLIC);
        self::el($inf, 'dhEvento', $agora->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->modify('-60 seconds')->format('Y-m-d\TH:i:sP'));
        self::el($inf, $tagAutor, $docAutor);
        self::el($inf, 'chNFSe', $chave);
        $ev = self::el($inf, 'e101101');
        self::el($ev, 'xDesc', 'Cancelamento de NFS-e');
        self::el($ev, 'cMotivo', (string) $cMotivo);
        self::el($ev, 'xMotivo', $motivo);

        return '<?xml version="1.0" encoding="UTF-8"?>' . $doc->saveXML($doc->documentElement);
    }

    private static function el(\DOMNode $pai, string $nome, ?string $valor = null): \DOMElement
    {
        $doc = $pai instanceof \DOMDocument ? $pai : $pai->ownerDocument;
        $no  = $doc->createElementNS(Dps::NS, $nome);
        if ($valor !== null) {
            $no->appendChild($doc->createTextNode($valor));
        }
        $pai->appendChild($no);
        return $no;
    }
}
