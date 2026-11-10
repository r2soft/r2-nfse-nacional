<?php

namespace R2soft\NfseNacional\Tests\Danfse;

use PHPUnit\Framework\TestCase;
use R2soft\NfseNacional\Danfse\DanfseDados;
use R2soft\NfseNacional\Danfse\Tabelas;
use R2soft\NfseNacional\NfseNacionalException;

/**
 * Cobre os itens de "DanfseDadosTest" em docs/danfse.md.
 *
 * Cenários sem fixture são derivados das fixtures com str_replace no próprio teste.
 */
class DanfseDadosTest extends TestCase
{
    private static function xml(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/danfse/' . $arquivo);
    }

    public function testExtraiOsDadosDasTresFixtures(): void
    {
        $casos = [
            'nfse-pj-suporte.xml' => [
                'numero' => '27',
                'numeroDps' => '679',
                'emissaoNfse' => '09/11/2026 17:16:31',
                'emissaoDps' => '09/11/2026 17:15:30',
                'valorOperacao' => 'R$ 189,83',
                'valorLiquido' => 'R$ 189,83',
                'tomador' => [
                    'cnpjCpf' => '11.222.333/0001-81',
                    'nome' => 'Imobiliária Exemplo de Teste Ltda',
                    'cMun' => '5218607',
                    'codigoIbgeCep' => '5218607 / 76.310-000',
                    'endereco' => 'Av. Exemplo, S/N, Centro',
                    'telefone' => '-',
                    'email' => '-',
                ],
            ],
            'nfse-pf-agenda.xml' => [
                'numero' => '12',
                'numeroDps' => '662',
                'emissaoNfse' => '06/11/2026 11:23:58',
                'emissaoDps' => '06/11/2026 11:22:57',
                'valorOperacao' => 'R$ 79,00',
                'valorLiquido' => 'R$ 79,00',
                'tomador' => [
                    'cnpjCpf' => '529.982.247-25',
                    'nome' => 'Cliente Pessoa Física de Teste',
                    'cMun' => '5211800',
                    'codigoIbgeCep' => '5211800 / 76.330-000',
                    'endereco' => 'RUA E-04, 610, QUADRA 06;LOTE 10, RESIDENCIAL TESTE',
                    'telefone' => '62999990000',
                    'email' => 'cliente@example.com',
                ],
            ],
            'nfse-assinatura.xml' => [
                'numero' => '40',
                'numeroDps' => '693',
                'emissaoNfse' => '09/11/2026 17:17:37',
                'emissaoDps' => '09/11/2026 17:16:35',
                'valorOperacao' => 'R$ 8,18',
                'valorLiquido' => 'R$ 8,18',
                'tomador' => [
                    'cnpjCpf' => '11.444.777/0001-61',
                    'nome' => 'Empreendimentos Rurais de Teste Ltda',
                    'cMun' => '5203302',
                    'codigoIbgeCep' => '5203302 / 75.240-000',
                    'endereco' => 'Rodovia de Teste, sn, Zona Rural',
                    'telefone' => '-',
                    'email' => '-',
                ],
            ],
        ];

        $totaisEsperados = 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: R$ 0,00; Estaduais: R$ 0,00; Municipais: R$ 0,00';

        foreach ($casos as $arquivo => $e) {
            $d = DanfseDados::doXml(self::xml($arquivo));

            // Cabeçalho
            $this->assertSame('Município: Jaraguá / GO', $d['cabecalho']['municipio'], $arquivo);
            $this->assertSame('Sefin Nacional NFS-e', $d['cabecalho']['ambienteGerador'], $arquivo);
            $this->assertSame('Produção', $d['cabecalho']['tipoAmbiente'], $arquivo);
            $this->assertNull($d['cabecalho']['aviso'], $arquivo);

            // Dados da NFS-e: chave = Id sem o prefixo "NFS", 50 dígitos num bloco só
            $n = $d['nfse'];
            $doc = new \DOMDocument();
            $doc->loadXML(self::xml($arquivo));
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('n', DanfseDados::NS);
            $chaveEsperada = (string) preg_replace('/^NFS/', '', (string) $xp->query('/n:NFSe/n:infNFSe/@Id')->item(0)->nodeValue);
            $this->assertSame($chaveEsperada, $n['chave'], $arquivo);
            $this->assertMatchesRegularExpression('/^\d{50}$/', $n['chave'], $arquivo);
            $this->assertStringStartsWith('52118002', $n['chave'], $arquivo);
            $this->assertSame($e['numero'], $n['numero'], $arquivo);
            $this->assertSame('01/11/2026', $n['competencia'], $arquivo);
            $this->assertSame($e['emissaoNfse'], $n['dataHoraEmissao'], $arquivo);
            $this->assertSame($e['numeroDps'], $n['numeroDps'], $arquivo);
            $this->assertSame('2', $n['serieDps'], $arquivo);
            $this->assertSame($e['emissaoDps'], $n['dataHoraEmissaoDps'], $arquivo);
            $this->assertSame('Prestador', $n['emitente'], $arquivo);
            $this->assertSame('NFS-e Gerada', $n['situacao'], $arquivo);
            $this->assertSame('-', $n['finalidade'], $arquivo);
            $this->assertSame('https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . $n['chave'], $n['urlConsulta'], $arquivo);

            // Prestador (nome e endereço caem no emit/enderNac)
            $p = $d['prestador'];
            $this->assertSame('15.401.765/0001-00', $p['cnpjCpf'], $arquivo);
            $this->assertSame('17200', $p['inscricaoMunicipal'], $arquivo);
            $this->assertSame('-', $p['telefone'], $arquivo);
            $this->assertSame('R2 SOFT INFORMATICA E SOFTWARES LTDA', $p['nome'], $arquivo);
            $this->assertSame('Jaraguá / GO', $p['municipioUf'], $arquivo);
            $this->assertSame('5211800 / 76.330-000', $p['codigoIbgeCep'], $arquivo);
            $this->assertSame('RUA R - 1, 2314, REGINA PARK', $p['endereco'], $arquivo);
            $this->assertSame('-', $p['email'], $arquivo);
            $this->assertSame('Optante - Microempresa ou Empresa de ...', $p['simplesNacional'], $arquivo);
            $this->assertSame('Regime de apuração dos tributos federais e municipal pelo SN', $p['regimeApuracao'], $arquivo);

            // Tomador
            $t = $d['tomador'];
            $this->assertSame($e['tomador']['cnpjCpf'], $t['cnpjCpf'], $arquivo);
            $this->assertSame($e['tomador']['nome'], $t['nome'], $arquivo);
            $this->assertSame('-', $t['inscricaoMunicipal'], $arquivo);
            $municipio = Tabelas::municipio($e['tomador']['cMun']);
            $this->assertNotNull($municipio, $arquivo);
            $this->assertSame($municipio[0] . ' / ' . $municipio[1], $t['municipioUf'], $arquivo);
            $this->assertSame($e['tomador']['codigoIbgeCep'], $t['codigoIbgeCep'], $arquivo);
            $this->assertSame($e['tomador']['endereco'], $t['endereco'], $arquivo);
            $this->assertSame($e['tomador']['telefone'], $t['telefone'], $arquivo);
            $this->assertSame($e['tomador']['email'], $t['email'], $arquivo);

            // Serviço
            $s = $d['servico'];
            $this->assertSame('01.07.01 / -', $s['codigoTributacao'], $arquivo);
            $this->assertSame('1.1501.30.00', $s['codigoNbs'], $arquivo);
            $this->assertSame('Jaraguá / GO / BR', $s['localPrestacao'], $arquivo);
            $this->assertSame('Suporte técnico em informática, inclusive instalação, configuração e manutenção de programas de computação e bancos de dados.', $s['descricaoCodigo'], $arquivo);

            // Tributação Municipal (ISSQN)
            $this->assertSame('Operação Tributável', $d['issqn'][1]['tipoTributacao'], $arquivo);
            $this->assertSame('Jaraguá / GO / BR', $d['issqn'][1]['municipioIncidencia'], $arquivo);
            $this->assertSame('Nenhum', $d['issqn'][2]['regimeEspecial'], $arquivo);
            $this->assertArrayNotHasKey(3, $d['issqn'], $arquivo);
            $this->assertSame('Não Retido', $d['issqn'][4]['retencao'], $arquivo);

            // Tributação Federal
            $this->assertSame('-', $d['tributacaoFederal'][1]['contribuicoesSociais'], $arquivo);
            $this->assertSame('-', $d['tributacaoFederal'][2]['pis'], $arquivo);
            $this->assertSame('PIS/COFINS/CSLL Não Retidos', $d['tributacaoFederal'][2]['descricaoRetPisCofins'], $arquivo);

            // IBS/CBS: sem IBSCBS no XML, "-" em tudo e o bloco continua
            $this->assertSame('- / -', $d['ibsCbs'][1]['cst'], $arquivo);
            $this->assertSame('- / - / - / -', $d['ibsCbs'][1]['indicadorOperacao'], $arquivo);
            $this->assertSame('-', $d['ibsCbs'][2]['exclusoes'], $arquivo);
            $this->assertSame('- / - / -', $d['ibsCbs'][2]['reducoesAliquota'], $arquivo);
            $this->assertSame('-', $d['ibsCbs'][4]['valorTotalIbs'], $arquivo);

            // Valor Total
            $this->assertSame($e['valorOperacao'], $d['valorTotal'][1]['valorOperacao'], $arquivo);
            $this->assertSame('-', $d['valorTotal'][1]['descontoIncondicionado'], $arquivo);
            $this->assertSame($e['valorLiquido'], $d['valorTotal'][2]['valorLiquido'], $arquivo);
            $this->assertSame('-', $d['valorTotal'][2]['totalIbsCbs'], $arquivo);
            $this->assertSame('-', $d['valorTotal'][2]['valorLiquidoTotal'], $arquivo);

            // Informações Complementares e marca d'água
            $this->assertSame($totaisEsperados, $d['informacoesComplementares']['totaisTributos'], $arquivo);
            $this->assertNull($d['marcaDagua'], $arquivo);
        }
    }

    public function testParaArrayDevolveOMesmoQueDoXml(): void
    {
        $xml = self::xml('nfse-pj-suporte.xml');
        $instancia = new DanfseDados($xml, ['cancelada' => true]);

        $this->assertSame(DanfseDados::doXml($xml, ['cancelada' => true]), $instancia->paraArray());
    }

    public function testDestinatario(): void
    {
        // Sem IBSCBS: não identificado
        $d = DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'));
        $this->assertSame(
            ['mensagem' => 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e'],
            $d['destinatario']
        );

        // indDest = 0: o destinatário é o próprio tomador
        $xml = str_replace('</toma>', '</toma><IBSCBS><indDest>0</indDest></IBSCBS>', self::xml('nfse-pj-suporte.xml'));
        $d2 = DanfseDados::doXml($xml);
        $this->assertSame(
            ['mensagem' => 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO'],
            $d2['destinatario']
        );

        // Com dest: mesmos campos do tomador, sem a Inscrição Municipal
        $xml3 = str_replace(
            '</toma>',
            '</toma><IBSCBS><dest><CPF>11144477735</CPF><xNome>Destinatário de Teste</xNome></dest></IBSCBS>',
            self::xml('nfse-pj-suporte.xml')
        );
        $d3 = DanfseDados::doXml($xml3);
        $this->assertSame('111.444.777-35', $d3['destinatario']['cnpjCpf']);
        $this->assertSame('Destinatário de Teste', $d3['destinatario']['nome']);
        $this->assertArrayNotHasKey('inscricaoMunicipal', $d3['destinatario']);
    }

    public function testIntermediario(): void
    {
        $d = DanfseDados::doXml(self::xml('nfse-pf-agenda.xml'));
        $this->assertSame(
            ['mensagem' => 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e'],
            $d['intermediario']
        );

        $xml = str_replace(
            '</toma>',
            '</toma><interm><CNPJ>12345678000199</CNPJ><xNome>Intermediário de Teste Ltda</xNome></interm>',
            self::xml('nfse-pf-agenda.xml')
        );
        $d2 = DanfseDados::doXml($xml);
        $this->assertSame('12.345.678/0001-99', $d2['intermediario']['cnpjCpf']);
        $this->assertSame('Intermediário de Teste Ltda', $d2['intermediario']['nome']);
    }

    public function testNota5LinhasDeBeneficioSomem(): void
    {
        // Com regEspTrib presente, a linha 2 sai (com "Nenhum"); a linha 3 não
        $d = DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'));
        $this->assertArrayHasKey(2, $d['issqn']);
        $this->assertArrayNotHasKey(3, $d['issqn']);

        // Sem nenhum campo da linha 2 no XML, ela some também
        $xml = str_replace('<regEspTrib>0</regEspTrib>', '', self::xml('nfse-pj-suporte.xml'));
        $d2 = DanfseDados::doXml($xml);
        $this->assertArrayNotHasKey(2, $d2['issqn']);
    }

    public function testNota6LinhaDoPisDependeDaCompetencia(): void
    {
        // Competência 2026: linha do PIS presente
        $d = DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'));
        $this->assertArrayHasKey(2, $d['tributacaoFederal']);

        // Competência 2027: linha do PIS ausente
        $xml = str_replace('<dCompet>2026-11-01</dCompet>', '<dCompet>2027-01-01</dCompet>', self::xml('nfse-pj-suporte.xml'));
        $d2 = DanfseDados::doXml($xml);
        $this->assertArrayNotHasKey(2, $d2['tributacaoFederal']);
        $this->assertSame('01/01/2027', $d2['nfse']['competencia']);
    }

    public function testTpRetPisCofinsUmSomaAsContribuicoesEZeraPisECofins(): void
    {
        $xml = str_replace(
            '<tribFed><piscofins><CST>00</CST><tpRetPisCofins>0</tpRetPisCofins></piscofins></tribFed>',
            '<tribFed><piscofins><CST>00</CST><tpRetPisCofins>1</tpRetPisCofins><vPis>2.00</vPis><vCofins>3.00</vCofins></piscofins><vRetCSLL>1.50</vRetCSLL></tribFed>',
            self::xml('nfse-pj-suporte.xml')
        );
        $d = DanfseDados::doXml($xml);
        $this->assertSame('R$ 6,50', $d['tributacaoFederal'][1]['contribuicoesSociais']);
        $this->assertSame('R$ 0,00', $d['tributacaoFederal'][2]['pis']);
        $this->assertSame('R$ 0,00', $d['tributacaoFederal'][2]['cofins']);
        $this->assertSame('PIS/COFINS Retido', $d['tributacaoFederal'][2]['descricaoRetPisCofins']);

        // Nos demais casos, CSLL fica isolada e PIS/COFINS saem do próprio piscofins
        $xml2 = str_replace(
            '<tribFed><piscofins><CST>00</CST><tpRetPisCofins>0</tpRetPisCofins></piscofins></tribFed>',
            '<tribFed><piscofins><CST>00</CST><tpRetPisCofins>3</tpRetPisCofins><vPis>2.00</vPis><vCofins>3.00</vCofins></piscofins><vRetCSLL>1.50</vRetCSLL></tribFed>',
            self::xml('nfse-pj-suporte.xml')
        );
        $d2 = DanfseDados::doXml($xml2);
        $this->assertSame('R$ 1,50', $d2['tributacaoFederal'][1]['contribuicoesSociais']);
        $this->assertSame('R$ 2,00', $d2['tributacaoFederal'][2]['pis']);
        $this->assertSame('R$ 3,00', $d2['tributacaoFederal'][2]['cofins']);
        $this->assertSame('PIS/COFINS/CSLL Retidos', $d2['tributacaoFederal'][2]['descricaoRetPisCofins']);
    }

    public function testTruncamentoComReticencias(): void
    {
        // Descrição do serviço acima do limite de 1300 caracteres
        $xml = str_replace(
            '<xDescServ>Prestação de serviços de suporte técnico em informática (NOVEMBRO/2026).</xDescServ>',
            '<xDescServ>' . str_repeat('a', 1310) . '</xDescServ>',
            self::xml('nfse-pj-suporte.xml')
        );
        $d = DanfseDados::doXml($xml);
        $this->assertSame(str_repeat('a', 1297) . '...', $d['servico']['descricaoServico']);
    }

    public function testInformacoesComplementaresTruncadasEm1997(): void
    {
        $xml = str_replace(
            '</cServ></serv>',
            '</cServ><infoCompl><xInfComp>' . str_repeat('b', 2000) . '</xInfComp></infoCompl></serv>',
            self::xml('nfse-pj-suporte.xml')
        );
        $d = DanfseDados::doXml($xml);
        $cheio = 'Inf. Cont.: ' . str_repeat('b', 2000);
        $this->assertSame(mb_substr($cheio, 0, 1994) . '...', $d['informacoesComplementares']['itens']);

        // A linha dos totais aproximados vem sempre no fim, nunca é cortada
        $this->assertSame(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: R$ 0,00; Estaduais: R$ 0,00; Municipais: R$ 0,00',
            $d['informacoesComplementares']['totaisTributos']
        );
    }

    public function testHomologacaoMostraAviso(): void
    {
        $d = DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'));
        $this->assertNull($d['cabecalho']['aviso']);

        $xml = str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', self::xml('nfse-pj-suporte.xml'));
        $d2 = DanfseDados::doXml($xml);
        $this->assertSame('NFS-e SEM VALIDADE JURÍDICA', $d2['cabecalho']['aviso']);
        $this->assertSame('Homologação', $d2['cabecalho']['tipoAmbiente']);
    }

    public function testItem99DoCTribNacEscondeOMunicipioDoCabecalho(): void
    {
        $d = DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'));
        $this->assertSame('Município: Jaraguá / GO', $d['cabecalho']['municipio']);

        $xml = str_replace('<cTribNac>010701</cTribNac>', '<cTribNac>990101</cTribNac>', self::xml('nfse-pj-suporte.xml'));
        $d2 = DanfseDados::doXml($xml);
        $this->assertNull($d2['cabecalho']['municipio']);
        $this->assertSame('99.01.01 / -', $d2['servico']['codigoTributacao']);
    }

    public function testMarcaDagua(): void
    {
        $this->assertNull(DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'))['marcaDagua']);
        $this->assertSame('CANCELADA', DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'), ['cancelada' => true])['marcaDagua']);
        $this->assertSame('SUBSTITUÍDA', DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'), ['substituida' => true])['marcaDagua']);
        // As duas juntas: vale CANCELADA
        $this->assertSame('CANCELADA', DanfseDados::doXml(self::xml('nfse-pj-suporte.xml'), ['cancelada' => true, 'substituida' => true])['marcaDagua']);
    }

    public function testXmlInvalidoLancaNfseNacionalException(): void
    {
        $this->expectException(NfseNacionalException::class);
        DanfseDados::doXml('isto não é XML');
    }

    public function testXmlSemInfNfseLancaNfseNacionalException(): void
    {
        $this->expectException(NfseNacionalException::class);
        DanfseDados::doXml('<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"></NFSe>');
    }

    public function testTabelasDeDescricaoEMunicipios(): void
    {
        $this->assertSame(['Jaraguá', 'GO'], Tabelas::municipio('5211800'));
        $this->assertNull(Tabelas::municipio('0000000'));
        $this->assertSame('Homologação', Tabelas::descricao('tpAmb', '2'));
        $this->assertSame('Sefin Nacional NFS-e', Tabelas::descricao('ambGer', '2'));
        // Código fora da tabela: imprime o próprio código
        $this->assertSame('9', Tabelas::descricao('tpAmb', '9'));
        $this->assertSame('-', Tabelas::descricao('tpAmb', null));
    }
}
