<?php

namespace R2soft\NfseNacional\Tests;

use PHPUnit\Framework\TestCase;
use R2soft\NfseNacional\NfseNacionalException;
use R2soft\NfseNacional\NfseNacionalService;
use R2soft\NfseNacional\SefinClient;
use R2soft\NfseNacional\SefinSemResposta;

/** Sefin simulada: cada chamada consome a próxima resposta da fila (array [status, json] ou exceção). */
class SefinFalsa extends SefinClient
{
    public $respostas;
    public $chamadas = [];

    public function __construct(array $respostas)
    {
        parent::__construct('hom', '', '');
        $this->respostas = $respostas;
    }

    protected function requisitar(string $metodo, string $caminho, ?string $corpo = null): array
    {
        $this->chamadas[] = [$metodo, $caminho, $corpo];
        $r = array_shift($this->respostas);
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return $r;
    }
}

class NfseNacionalServiceTest extends TestCase
{
    private const CHAVE = '52118002211222333000181000000000064526110000000001';

    /** @var SefinFalsa */
    private $sefin;

    private function servico(array $respostas): NfseNacionalService
    {
        $this->sefin = new SefinFalsa($respostas);
        return new NfseNacionalService(
            function () {
                return $this->sefin;
            },
            static function () {
                return new \DateTimeImmutable('2026-11-03T15:00:00-03:00');
            }
        );
    }

    private static function config(): array
    {
        static $pfx;
        $pfx = $pfx ?? CertificadoDeTeste::pfx('s');
        return ['ambiente' => 'prod', 'certificado_conteudo' => $pfx, 'certificado_senha' => 's'];
    }

    private static function nfseGzip(string $numero): string
    {
        return base64_encode(gzencode('<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . self::CHAVE . '"><nNFSe>' . $numero . '</nNFSe></infNFSe></NFSe>'));
    }

    public function testEmissaoAceitaDevolveNumeroEChave(): void
    {
        $r = $this->servico([[201, ['chaveAcesso' => self::CHAVE, 'nfseXmlGZipB64' => self::nfseGzip('3901')]]])
            ->emitir(self::config(), DpsTest::dados());

        $this->assertTrue($r['sucesso']);
        $this->assertSame('3901', $r['numero_nfse']);
        $this->assertSame(self::CHAVE, $r['codigo_verificacao']);
        $this->assertStringContainsString('<nNFSe>3901</nNFSe>', $r['xml_retorno']);
        $this->assertStringContainsString('<Signature', $r['xml_envio']);
        $this->assertNull($r['mensagem']);

        [$metodo, $caminho, $corpo] = $this->sefin->chamadas[0];
        $this->assertSame(['POST', '/nfse'], [$metodo, $caminho]);
        $this->assertSame($r['xml_envio'], gzdecode(base64_decode(json_decode($corpo, true)['dpsXmlGZipB64'])));
    }

    public function testAceitaSemXmlBuscaANotaPelaChave(): void
    {
        $r = $this->servico([
            [201, ['chaveAcesso' => self::CHAVE]],
            [200, ['chaveAcesso' => self::CHAVE, 'nfseXmlGZipB64' => self::nfseGzip('3902')]],
        ])->emitir(self::config(), DpsTest::dados());

        $this->assertTrue($r['sucesso']);
        $this->assertSame('3902', $r['numero_nfse']);
        $this->assertSame(['GET', '/nfse/' . self::CHAVE], array_slice($this->sefin->chamadas[1], 0, 2));
    }

    public function testRecusaDaSefinEhConclusivaComCodigoEDescricao(): void
    {
        $r = $this->servico([[400, ['erros' => [
            ['Codigo' => 'E0188', 'Descricao' => 'CNPJ do tomador informado na DPS é inválido.'],
            ['Codigo' => 'E1235', 'Descricao' => 'Falha no esquema XML do DF-e.', 'Complemento' => 'cNBS inválido'],
        ]]]])->emitir(self::config(), DpsTest::dados());

        $this->assertFalse($r['sucesso']);
        $this->assertSame('E0188 – CNPJ do tomador informado na DPS é inválido.; E1235 – Falha no esquema XML do DF-e. (cNBS inválido)', $r['mensagem']);
        $this->assertStringNotContainsString(NfseNacionalService::SEM_RESPOSTA, $r['mensagem']);
    }

    public function testEsquemaNovoDaSefinReenviaUmaVezNoLeiauteDaReforma(): void
    {
        $r = $this->servico([
            [400, ['erros' => [['Codigo' => 'E1235', 'Descricao' => 'Falha no esquema XML do DF-e.',
                'Complemento' => "The element 'infDPS' has invalid child element 'cLocEmi'. List of possible elements expected: 'cMotivoEmisTI, finNFSe'."]]]],
            [201, ['chaveAcesso' => self::CHAVE, 'nfseXmlGZipB64' => self::nfseGzip('3904')]],
        ])->emitir(self::config(), DpsTest::dados());

        $this->assertTrue($r['sucesso']);
        $this->assertSame('3904', $r['numero_nfse']);
        $this->assertCount(2, $this->sefin->chamadas);
        $this->assertStringContainsString('<finNFSe>0</finNFSe>', $r['xml_envio']);
        $this->assertStringNotContainsString('<cNBS>', $r['xml_envio']);
    }

    public function testOutroErroDeEsquemaNaoReenvia(): void
    {
        $r = $this->servico([[400, ['erros' => [['Codigo' => 'E1235', 'Descricao' => 'Falha no esquema XML do DF-e.', 'Complemento' => 'xNome inválido']]]]])
            ->emitir(self::config(), DpsTest::dados());

        $this->assertFalse($r['sucesso']);
        $this->assertCount(1, $this->sefin->chamadas);
    }

    public function testCertificadoRecusadoSemCorpoEhConclusivo(): void
    {
        $r = $this->servico([[403, null]])->emitir(self::config(), DpsTest::dados());
        $this->assertSame('Sefin recusou a requisição: HTTP 403', $r['mensagem']);
    }

    public function testTimeoutE5xxViramSemResposta(): void
    {
        $r = $this->servico([new SefinSemResposta('Operation timed out after 40000 milliseconds')])->emitir(self::config(), DpsTest::dados());
        $this->assertFalse($r['sucesso']);
        $this->assertSame('Sem resposta da Sefin: Operation timed out after 40000 milliseconds', $r['mensagem']);

        $r = $this->servico([[502, null]])->emitir(self::config(), DpsTest::dados());
        $this->assertSame('Sem resposta da Sefin: HTTP 502', $r['mensagem']);
    }

    public function testSemCertificadoLancaExcecaoDeConfiguracao(): void
    {
        $this->expectException(NfseNacionalException::class);
        $this->servico([])->emitir(['ambiente' => 'prod', 'certificado_conteudo' => '', 'certificado_senha' => ''], DpsTest::dados());
    }

    private static function dadosConsulta(): array
    {
        return ['numero_rps' => '645', 'serie' => '2', 'codigo_municipio' => '5211800', 'prestador_cpf_cnpj' => '11222333000181', 'prestador_tipo' => 'CNPJ'];
    }

    public function testConsultaAchaPeloIdDaDps(): void
    {
        $r = $this->servico([
            [200, ['chaveAcesso' => self::CHAVE]],
            [200, ['chaveAcesso' => self::CHAVE, 'nfseXmlGZipB64' => self::nfseGzip('3903')]],
        ])->consultar(self::config(), self::dadosConsulta());

        $this->assertSame('/dps/DPS521180021122233300018100002000000000000645', $this->sefin->chamadas[0][1]);
        $this->assertTrue($r['encontrada']);
        $this->assertTrue($r['sucesso']);
        $this->assertFalse($r['cancelada']);
        $this->assertSame('3903', $r['numero_nfse']);
        $this->assertSame(self::CHAVE, $r['codigo_verificacao']);
    }

    public function testConsulta404EhNaoEncontrada(): void
    {
        $r = $this->servico([[404, ['erro' => ['codigo' => 'E2404', 'descricao' => 'Não foi gerada uma NFS-e com o identificador de DPS informado']]]])
            ->consultar(self::config(), self::dadosConsulta());
        $this->assertFalse($r['encontrada']);
    }

    public function testConsultaSemRespostaEhInconclusiva(): void
    {
        $r = $this->servico([new SefinSemResposta('timeout')])->consultar(self::config(), self::dadosConsulta());
        $this->assertNull($r['encontrada']);
        $this->assertSame('Sem resposta da Sefin: timeout', $r['mensagem']);

        $r = $this->servico([[500, null]])->consultar(self::config(), self::dadosConsulta());
        $this->assertNull($r['encontrada']);
    }

    private static function dadosCancelamento(): array
    {
        return ['chave' => self::CHAVE, 'prestador_cpf_cnpj' => '11222333000181', 'motivo_codigo' => 1, 'motivo_texto' => 'Nota emitida com dados incorretos'];
    }

    private static function eventoGzip(): string
    {
        return base64_encode(gzencode('<pedRegEvento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infPedReg Id="PRE' . self::CHAVE . '101101"><chNFSe>' . self::CHAVE . '</chNFSe></infPedReg></pedRegEvento>'));
    }

    public function testCancelamentoAceitoDevolveXmlDoEvento(): void
    {
        $r = $this->servico([[201, ['eventoXmlGZipB64' => self::eventoGzip()]]])
            ->cancelar(self::config(), self::dadosCancelamento());

        $this->assertTrue($r['sucesso']);
        $this->assertStringContainsString('<pedRegEvento', $r['xml_retorno']);
        $this->assertStringContainsString('<Signature', $r['xml_envio']);
        $this->assertNull($r['mensagem']);

        [$metodo, $caminho, $corpo] = $this->sefin->chamadas[0];
        $this->assertSame(['POST', '/nfse/' . self::CHAVE . '/eventos'], [$metodo, $caminho]);
        $this->assertSame($r['xml_envio'], gzdecode(base64_decode(json_decode($corpo, true)['pedidoRegistroEventoXmlGZipB64'])));
    }

    public function testChaveComMascaraVaiLimpaParaAUrl(): void
    {
        $dados = ['chave' => implode(' ', str_split(self::CHAVE, 10))] + self::dadosCancelamento();
        $this->servico([[201, ['eventoXmlGZipB64' => self::eventoGzip()]]])->cancelar(self::config(), $dados);
        $this->assertSame('/nfse/' . self::CHAVE . '/eventos', $this->sefin->chamadas[0][1]);
    }

    public function testCancelamentoRecusadoComErroLista(): void
    {
        $r = $this->servico([[400, ['erro' => [
            ['codigo' => 'E1831', 'descricao' => 'O evento não pode ser recepcionado pois a NFS-e indicada não existe no Ambiente de Dados Nacional NFS-e.'],
        ]]]])->cancelar(self::config(), self::dadosCancelamento());

        $this->assertFalse($r['sucesso']);
        $this->assertSame('E1831 – O evento não pode ser recepcionado pois a NFS-e indicada não existe no Ambiente de Dados Nacional NFS-e.', $r['mensagem']);
        $this->assertStringNotContainsString(NfseNacionalService::SEM_RESPOSTA, $r['mensagem']);
    }

    public function testCancelamentoRecusadoComErroObjetoUnico(): void
    {
        $r = $this->servico([[400, ['erro' => ['codigo' => 'E1832', 'descricao' => 'O evento de cancelamento não é permitido para esta NFS-e.']]]])
            ->cancelar(self::config(), self::dadosCancelamento());

        $this->assertFalse($r['sucesso']);
        $this->assertSame('E1832 – O evento de cancelamento não é permitido para esta NFS-e.', $r['mensagem']);
    }

    public function testCancelamentoRecusadoSemErrosNoCorpo(): void
    {
        $r = $this->servico([[403, null]])->cancelar(self::config(), self::dadosCancelamento());
        $this->assertSame('Sefin recusou o cancelamento: HTTP 403', $r['mensagem']);
    }

    public function testCancelamentoSemRespostaEhInconclusivo(): void
    {
        $r = $this->servico([new SefinSemResposta('Operation timed out after 40000 milliseconds')])
            ->cancelar(self::config(), self::dadosCancelamento());
        $this->assertFalse($r['sucesso']);
        $this->assertStringStartsWith('Sem resposta da Sefin: ', $r['mensagem']);

        $r = $this->servico([[502, null]])->cancelar(self::config(), self::dadosCancelamento());
        $this->assertSame('Sem resposta da Sefin: HTTP 502', $r['mensagem']);
    }

    public function testEmissaoRecusadaAceitaErroSingularListaEObjetoUnico(): void
    {
        $r = $this->servico([[400, ['erro' => [['codigo' => 'E0188', 'descricao' => 'CNPJ do tomador informado na DPS é inválido.']]]]])
            ->emitir(self::config(), DpsTest::dados());
        $this->assertFalse($r['sucesso']);
        $this->assertSame('E0188 – CNPJ do tomador informado na DPS é inválido.', $r['mensagem']);

        $r = $this->servico([[400, ['erro' => ['codigo' => 'E0188', 'descricao' => 'CNPJ do tomador informado na DPS é inválido.']]]])
            ->emitir(self::config(), DpsTest::dados());
        $this->assertFalse($r['sucesso']);
        $this->assertSame('E0188 – CNPJ do tomador informado na DPS é inválido.', $r['mensagem']);
    }
}
