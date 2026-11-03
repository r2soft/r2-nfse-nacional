<?php

namespace R2soft\NfseNacional\Tests;

use PHPUnit\Framework\TestCase;
use R2soft\NfseNacional\AssinadorXml;
use R2soft\NfseNacional\Dps;
use R2soft\NfseNacional\NfseNacionalException;

class AssinadorXmlTest extends TestCase
{
    private const DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    public function testAssinaOInfDpsComDigestEAssinaturaQueConferem(): void
    {
        $pfx = CertificadoDeTeste::pfx('segredo');
        $xml = (new AssinadorXml($pfx, 'segredo'))->assinar(
            Dps::xml(DpsTest::dados(), 'hom', new \DateTimeImmutable('2026-11-03T15:00:00-03:00'))
        );

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><DPS ', $xml);

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('n', Dps::NS);
        $xp->registerNamespace('ds', self::DSIG);

        $inf = $xp->query('/n:DPS/n:infDPS')->item(0);
        $this->assertSame('#' . $inf->getAttribute('Id'), $xp->query('//ds:Reference')->item(0)->getAttribute('URI'));
        $this->assertSame(
            base64_encode(hash('sha256', $inf->C14N(), true)),
            $xp->query('//ds:DigestValue')->item(0)->textContent
        );
        $this->assertSame('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', $xp->query('//ds:SignatureMethod')->item(0)->getAttribute('Algorithm'));

        $signedInfo = $xp->query('//ds:SignedInfo')->item(0)->C14N();
        $assinatura = base64_decode($xp->query('//ds:SignatureValue')->item(0)->textContent);
        $publica    = openssl_pkey_get_public(CertificadoDeTeste::certificadoPem($pfx, 'segredo'));
        $this->assertSame(1, openssl_verify($signedInfo, $assinatura, $publica, OPENSSL_ALGO_SHA256));
        $this->assertNotSame('', $xp->query('//ds:X509Certificate')->item(0)->textContent);
    }

    public function testSenhaErradaDaErroClaro(): void
    {
        $this->expectException(NfseNacionalException::class);
        $this->expectExceptionMessage('certificado A1');
        new AssinadorXml(CertificadoDeTeste::pfx('certa'), 'errada');
    }
}
