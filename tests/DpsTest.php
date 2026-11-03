<?php

namespace R2soft\NfseNacional\Tests;

use PHPUnit\Framework\TestCase;
use R2soft\NfseNacional\Dps;
use R2soft\NfseNacional\NfseNacionalException;

class DpsTest extends TestCase
{
    private const AGORA = '2026-11-03T15:00:00-03:00';

    public static function dados(array $troca = []): array
    {
        return array_merge([
            'numero_rps'               => '645',
            'serie'                    => '2',
            'competencia'              => '2026-11-01',
            'valor_servicos'           => '419.6',
            'discriminacao'            => "Suporte técnico\n em informática & cia <novembro>",
            'codigo_municipio'         => '5211800',
            'numero_nbs'               => '1.1501.30.00',
            'item_lista_servico'       => '01.07.01',
            'optante_simples_nacional' => 1,
            'prestador_cpf_cnpj'       => '11222333000181',
            'prestador_tipo'           => 'CNPJ',
            'prestador_im'             => '17200',
            'tomador_cpf_cnpj'         => '44555666000199',
            'tomador_razao_social'     => 'CLIENTE FICTICIO LTDA',
            'tomador_endereco'         => 'Rua Um',
            'tomador_numero'           => '',
            'tomador_complemento'      => 'Sala 6',
            'tomador_bairro'           => 'Centro',
            'tomador_cep'              => '75901-180',
            'tomador_cod_municipio'    => '5218805',
        ], $troca);
    }

    private static function xml(array $troca = [], string $ambiente = 'prod'): \DOMXPath
    {
        $doc = new \DOMDocument();
        $doc->loadXML(Dps::xml(self::dados($troca), $ambiente, new \DateTimeImmutable(self::AGORA)));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('n', Dps::NS);
        return $xp;
    }

    private static function valor(\DOMXPath $xp, string $caminho): ?string
    {
        $no = $xp->query($caminho)->item(0);
        return $no ? $no->textContent : null;
    }

    public function testIdTem45CaracteresNoLayoutDaSefin(): void
    {
        $this->assertSame('DPS521180021122233300018100002000000000000645', Dps::id('5211800', 'CNPJ', '11.222.333/0001-81', '2', '645'));
        $this->assertSame(45, strlen(Dps::id('5211800', 'CPF', '12345678901', '2', '1')));
        $this->assertSame('DPS5211800100012345678901', substr(Dps::id('5211800', 'CPF', '12345678901', '2', '1'), 0, 25));
    }

    public function testMontaODpsComOsCamposNaOrdemDoEsquema(): void
    {
        $xp = self::xml();
        $inf = $xp->query('/n:DPS/n:infDPS')->item(0);
        $this->assertSame('1.01', $xp->query('/n:DPS')->item(0)->getAttribute('versao'));
        $this->assertSame('DPS521180021122233300018100002000000000000645', $inf->getAttribute('Id'));

        $filhos = [];
        foreach ($inf->childNodes as $n) {
            $filhos[] = $n->localName;
        }
        $this->assertSame(['tpAmb', 'dhEmi', 'verAplic', 'serie', 'nDPS', 'dCompet', 'tpEmit', 'cLocEmi', 'prest', 'toma', 'serv', 'valores'], $filhos);

        $this->assertSame('1', self::valor($xp, '//n:tpAmb'));
        $this->assertSame('2026-11-03T14:59:00-03:00', self::valor($xp, '//n:dhEmi'));
        $this->assertSame('2', self::valor($xp, '//n:serie'));
        $this->assertSame('645', self::valor($xp, '//n:nDPS'));
        $this->assertSame('2026-11-01', self::valor($xp, '//n:dCompet'));
        $this->assertSame('11222333000181', self::valor($xp, '//n:prest/n:CNPJ'));
        $this->assertSame('17200', self::valor($xp, '//n:prest/n:IM'));
        $this->assertSame('3', self::valor($xp, '//n:prest/n:regTrib/n:opSimpNac'));
        $this->assertSame('1', self::valor($xp, '//n:prest/n:regTrib/n:regApTribSN'));
        $this->assertSame('0', self::valor($xp, '//n:prest/n:regTrib/n:regEspTrib'));
        $this->assertSame('44555666000199', self::valor($xp, '//n:toma/n:CNPJ'));
        $this->assertSame('5218805', self::valor($xp, '//n:toma/n:end/n:endNac/n:cMun'));
        $this->assertSame('75901180', self::valor($xp, '//n:toma/n:end/n:endNac/n:CEP'));
        $this->assertSame('S/N', self::valor($xp, '//n:toma/n:end/n:nro'));
        $this->assertSame('Sala 6', self::valor($xp, '//n:toma/n:end/n:xCpl'));
        $this->assertSame('5211800', self::valor($xp, '//n:serv/n:locPrest/n:cLocPrestacao'));
        $this->assertSame('010701', self::valor($xp, '//n:serv/n:cServ/n:cTribNac'));
        $this->assertSame('Suporte técnico em informática & cia <novembro>', self::valor($xp, '//n:serv/n:cServ/n:xDescServ'));
        $this->assertSame('115013000', self::valor($xp, '//n:serv/n:cServ/n:cNBS'));
        $this->assertSame('419.60', self::valor($xp, '//n:valores/n:vServPrest/n:vServ'));
        $this->assertSame('1', self::valor($xp, '//n:trib/n:tribMun/n:tribISSQN'));
        $this->assertSame('1', self::valor($xp, '//n:trib/n:tribMun/n:tpRetISSQN'));
        $this->assertSame('00', self::valor($xp, '//n:trib/n:tribFed/n:piscofins/n:CST'));
        $this->assertSame('0.00', self::valor($xp, '//n:trib/n:totTrib/n:vTotTrib/n:vTotTribMun'));
    }

    public function testHomologacaoUsaTpAmb2ESeriePadraoQuandoVazia(): void
    {
        $xp = self::xml(['serie' => ''], 'hom');
        $this->assertSame('2', self::valor($xp, '//n:tpAmb'));
        $this->assertSame(Dps::SERIE_PADRAO, self::valor($xp, '//n:serie'));
    }

    public function testTomadorCpfSemEnderecoNaoLevaEnd(): void
    {
        $xp = self::xml(['tomador_cpf_cnpj' => '123.456.789-01', 'tomador_cep' => '', 'tomador_cod_municipio' => '']);
        $this->assertSame('12345678901', self::valor($xp, '//n:toma/n:CPF'));
        $this->assertNull(self::valor($xp, '//n:toma/n:end'));
    }

    public function testSemNbsNaoLevaCNBS(): void
    {
        $this->assertNull(self::valor(self::xml(['numero_nbs' => '']), '//n:cNBS'));
    }

    public function testRecusaEmpresaForaDoSimples(): void
    {
        $this->expectException(NfseNacionalException::class);
        Dps::xml(self::dados(['optante_simples_nacional' => 2]), 'prod', new \DateTimeImmutable(self::AGORA));
    }

    public function testRecusaItemDaListaQueNaoViraCodigoNacional(): void
    {
        $this->expectException(NfseNacionalException::class);
        Dps::xml(self::dados(['item_lista_servico' => '1.07']), 'prod', new \DateTimeImmutable(self::AGORA));
    }

    public function testRecusaValorZero(): void
    {
        $this->expectException(NfseNacionalException::class);
        Dps::xml(self::dados(['valor_servicos' => '0']), 'prod', new \DateTimeImmutable(self::AGORA));
    }
}
