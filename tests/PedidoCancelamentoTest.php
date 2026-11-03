<?php

namespace R2soft\NfseNacional\Tests;

use PHPUnit\Framework\TestCase;
use R2soft\NfseNacional\Dps;
use R2soft\NfseNacional\NfseNacionalException;
use R2soft\NfseNacional\PedidoCancelamento;

class PedidoCancelamentoTest extends TestCase
{
    private const CHAVE = '52118002211222333000181000000000064526110000000001';

    private function infPedReg(string $docAutor = '11222333000181', int $motivo = 1, string $texto = 'Nota emitida com dados incorretos', string $ambiente = 'hom'): \DOMElement
    {
        $xml = PedidoCancelamento::xml(self::CHAVE, $docAutor, $motivo, $texto, $ambiente, new \DateTimeImmutable('2026-11-03T15:00:00-03:00'));
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        return $doc->getElementsByTagNameNS(Dps::NS, 'infPedReg')->item(0);
    }

    public function testRaizPedRegEventoComVersao(): void
    {
        $xml = PedidoCancelamento::xml(self::CHAVE, '11222333000181', 1, 'Nota emitida com dados incorretos', 'hom', new \DateTimeImmutable('2026-11-03T15:00:00-03:00'));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><pedRegEvento ', $xml);

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $this->assertSame('pedRegEvento', $doc->documentElement->localName);
        $this->assertSame(Dps::NS, $doc->documentElement->namespaceURI);
        $this->assertSame('1.01', $doc->documentElement->getAttribute('versao'));
    }

    public function testOrdemDosFilhosDoInfPedReg(): void
    {
        $nomes = [];
        foreach ($this->infPedReg()->childNodes as $no) {
            if ($no instanceof \DOMElement) {
                $nomes[] = $no->localName;
            }
        }
        $this->assertSame(['tpAmb', 'verAplic', 'dhEvento', 'CNPJAutor', 'chNFSe', 'e101101'], $nomes);
    }

    public function testIdEhPreMaisChaveMaisCodigoDoEvento(): void
    {
        $this->assertSame('PRE' . self::CHAVE . '101101', $this->infPedReg()->getAttribute('Id'));
    }

    public function testTpAmbEh1NaProducaoE2NaHomologacao(): void
    {
        $this->assertSame('1', $this->infPedReg('11222333000181', 1, 'Nota emitida com dados incorretos', 'prod')
            ->getElementsByTagNameNS(Dps::NS, 'tpAmb')->item(0)->textContent);
        $this->assertSame('2', $this->infPedReg()
            ->getElementsByTagNameNS(Dps::NS, 'tpAmb')->item(0)->textContent);
    }

    public function testDhEventoEhAgoraMenos60Segundos(): void
    {
        $dh = $this->infPedReg()->getElementsByTagNameNS(Dps::NS, 'dhEvento')->item(0)->textContent;
        $this->assertSame('2026-11-03T14:59:00-03:00', $dh);
    }

    public function testCpfAutorUsaCPFAutor(): void
    {
        $inf = $this->infPedReg('39053344705');
        $this->assertSame('39053344705', $inf->getElementsByTagNameNS(Dps::NS, 'CPFAutor')->item(0)->textContent);
        $this->assertSame(0, $inf->getElementsByTagNameNS(Dps::NS, 'CNPJAutor')->length);
    }

    public function testXMotivoComEspacosColapsadosEEscapado(): void
    {
        $xml = PedidoCancelamento::xml(self::CHAVE, '11222333000181', 1, "Emissão com   dados & <incorretos>\n repetidos", 'hom', new \DateTimeImmutable('2026-11-03T15:00:00-03:00'));
        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringContainsString('&lt;', $xml);

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xMotivo = $doc->getElementsByTagNameNS(Dps::NS, 'xMotivo')->item(0)->textContent;
        $this->assertSame('Emissão com dados & <incorretos> repetidos', $xMotivo);
    }

    public function testChaveCom49DigitosDaErro(): void
    {
        $this->expectException(NfseNacionalException::class);
        $this->expectExceptionMessage('50 dígitos');
        PedidoCancelamento::xml(substr(self::CHAVE, 0, 49), '11222333000181', 1, 'Nota emitida com dados incorretos', 'hom', new \DateTimeImmutable('2026-11-03T15:00:00-03:00'));
    }

    public function testMotivoCodigo3DaErro(): void
    {
        $this->expectException(NfseNacionalException::class);
        $this->expectExceptionMessage('Código do motivo');
        PedidoCancelamento::xml(self::CHAVE, '11222333000181', 3, 'Nota emitida com dados incorretos', 'hom', new \DateTimeImmutable('2026-11-03T15:00:00-03:00'));
    }

    public function testXMotivoCom14CaracteresDaErro(): void
    {
        $this->expectException(NfseNacionalException::class);
        $this->expectExceptionMessage('O motivo do cancelamento deve ter de 15 a 255 caracteres.');
        PedidoCancelamento::xml(self::CHAVE, '11222333000181', 1, '12345678901234', 'hom', new \DateTimeImmutable('2026-11-03T15:00:00-03:00'));
    }

    public function testDocumentoCom12DigitosDaErro(): void
    {
        $this->expectException(NfseNacionalException::class);
        $this->expectExceptionMessage('14 dígitos');
        PedidoCancelamento::xml(self::CHAVE, '123456789012', 1, 'Nota emitida com dados incorretos', 'hom', new \DateTimeImmutable('2026-11-03T15:00:00-03:00'));
    }
}
