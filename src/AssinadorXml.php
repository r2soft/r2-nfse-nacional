<?php

namespace R2soft\NfseNacional;

/** Assinatura XMLDSig do infDPS: enveloped, RSA-SHA256, C14N, certificado no KeyInfo. */
final class AssinadorXml
{
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    /** @var string */
    private $chave;
    /** @var string */
    private $certificado;

    public function __construct(string $pfx, string $senha)
    {
        if ($pfx === '' || !openssl_pkcs12_read($pfx, $certs, $senha)) {
            throw new NfseNacionalException('Não foi possível abrir o certificado A1 (arquivo PFX ou senha inválidos).');
        }
        $this->chave       = $certs['pkey'];
        $this->certificado = preg_replace('/-----[^-]+-----|\s/', '', $certs['cert']);
    }

    public function assinar(string $xmlDps): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($xmlDps);
        $inf = $doc->getElementsByTagNameNS(Dps::NS, 'infDPS')->item(0);
        if (!$inf) {
            throw new NfseNacionalException('XML sem infDPS para assinar.');
        }

        $digest     = base64_encode(hash('sha256', $inf->C14N(), true));
        $signedInfo = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . '<CanonicalizationMethod Algorithm="' . self::C14N . '"/>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            . '<Reference URI="#' . $inf->getAttribute('Id') . '"><Transforms>'
            . '<Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
            . '<Transform Algorithm="' . self::C14N . '"/>'
            . '</Transforms><DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            . '<DigestValue>' . $digest . '</DigestValue></Reference></SignedInfo>';

        $si = new \DOMDocument();
        $si->loadXML($signedInfo);
        if (!openssl_sign($si->documentElement->C14N(), $assinatura, $this->chave, OPENSSL_ALGO_SHA256)) {
            throw new NfseNacionalException('Falha ao assinar a DPS com o certificado A1.');
        }

        $frag = $doc->createDocumentFragment();
        $frag->appendXML('<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">' . $signedInfo
            . '<SignatureValue>' . base64_encode($assinatura) . '</SignatureValue>'
            . '<KeyInfo><X509Data><X509Certificate>' . $this->certificado . '</X509Certificate></X509Data></KeyInfo>'
            . '</Signature>');
        $doc->documentElement->appendChild($frag);

        return '<?xml version="1.0" encoding="UTF-8"?>' . $doc->saveXML($doc->documentElement);
    }
}
