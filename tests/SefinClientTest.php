<?php

namespace R2soft\NfseNacional\Tests;

use PHPUnit\Framework\TestCase;
use R2soft\NfseNacional\SefinClient;
use R2soft\NfseNacional\SefinSemResposta;

class SefinClientTest extends TestCase
{
    public function testUrlPorAmbiente(): void
    {
        $this->assertSame('https://sefin.nfse.gov.br/SefinNacional', SefinClient::urlBase('prod'));
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional', SefinClient::urlBase('hom'));
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional', SefinClient::urlBase('mock'));
    }

    public function testServidorForaDoArViraSemResposta(): void
    {
        // Porta 9 local: conexão recusada na hora, sem depender da internet.
        $cliente = new SefinClient('hom', CertificadoDeTeste::pfx('x'), 'x', 'https://127.0.0.1:9/SefinNacional');
        $this->expectException(SefinSemResposta::class);
        $cliente->consultarDps('DPS521180021122233300018100002000000000000645');
    }

    public function testNaoDeixaOCertificadoNoDisco(): void
    {
        $antes   = glob(sys_get_temp_dir() . '/nfse_pfx_*') ?: [];
        $cliente = new SefinClient('hom', CertificadoDeTeste::pfx('x'), 'x', 'https://127.0.0.1:9/SefinNacional');
        try {
            $cliente->consultarNfse('1');
        } catch (SefinSemResposta $e) {
        }
        $this->assertSame($antes, glob(sys_get_temp_dir() . '/nfse_pfx_*') ?: []);
    }
}
