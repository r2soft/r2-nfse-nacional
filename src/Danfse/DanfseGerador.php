<?php

namespace R2soft\NfseNacional\Danfse;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;

/**
 * DANFSe v2.0 (NT SE/CGNFS-e 008/2026) a partir do XML da NFS-e do padrão nacional.
 *
 *   $pdf  = (new DanfseGerador())->gerarPdf($xml, ['cancelada' => true]);
 *   $html = (new DanfseGerador())->gerarHtml($xml);
 *
 * XML inválido ou sem infNFSe lança R2soft\NfseNacional\NfseNacionalException.
 */
class DanfseGerador
{
    private const LOGO = __DIR__ . '/../../resources/danfse/logo-nfse.png';

    /**
     * @param array{cancelada?: bool, substituida?: bool} $opcoes
     * @return string bytes do PDF ("%PDF...")
     */
    public function gerarPdf(string $xml, array $opcoes = []): string
    {
        $dompdf = new Dompdf([
            'defaultFont'     => 'Helvetica',
            'isRemoteEnabled' => false,
            'chroot'          => [realpath(__DIR__ . '/../..')],
        ]);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->gerarHtml($xml, $opcoes), 'UTF-8');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** @param array{cancelada?: bool, substituida?: bool} $opcoes */
    public function gerarHtml(string $xml, array $opcoes = []): string
    {
        $dados = DanfseDados::doXml($xml, $opcoes);
        $dados['imagens'] = [
            'logo' => 'data:image/png;base64,' . base64_encode((string) file_get_contents(self::LOGO)),
            'qr'   => self::qrCode($dados['nfse']['urlConsulta']),
        ];
        return DanfseHtml::render($dados);
    }

    /** PNG em data URI. */
    public static function qrCode(string $conteudo): string
    {
        $opcoes = new QROptions([
            'outputType'   => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'eccLevel'     => EccLevel::M,
            'scale'        => 4,
            'quietzoneSize' => 1,
        ]);
        return (new QRCode($opcoes))->render($conteudo);
    }
}
