<?php

namespace R2soft\NfseNacional\Danfse;

use DOMDocument;
use DOMXPath;
use R2soft\NfseNacional\NfseNacionalException;

/**
 * Extrai e formata os dados de uma NFS-e do padrão nacional para o DANFSe v2.0
 * (NT SE/CGNFS-e nº 008/2026). Não gera HTML — só os textos prontos para exibir.
 *
 * Entrada: XML da NFS-e com raiz <NFSe> em http://www.sped.fazenda.gov.br/nfse,
 * com infNFSe e a DPS dentro (o mesmo que a Sefin devolve). XML inválido ou sem
 * infNFSe lança R2soft\NfseNacional\NfseNacionalException.
 *
 * Uso:
 *   $dados = DanfseDados::doXml($xml, ['cancelada' => true]);
 *   // ou
 *   $dados = (new DanfseDados($xml, ['substituida' => true]))->paraArray();
 *
 * @see docs/danfse.md (seção "Campos")
 *
 * O array é agrupado por bloco e todo valor já é string pronta para exibir.
 * Campo sem informação no XML vira "-"; truncamentos terminam com "...".
 *
 * cabecalho: municipio (string "Município: X / UF" ou null quando o item do
 *            cTribNac é 99), ambienteGerador, tipoAmbiente, aviso
 *            (null ou "NFS-e SEM VALIDADE JURÍDICA").
 * nfse: chave (50 dígitos, sem o prefixo "NFS"), numero, competencia,
 *       dataHoraEmissao, numeroDps, serieDps, dataHoraEmissaoDps, emitente,
 *       situacao, finalidade, urlConsulta.
 * prestador: cnpjCpf, inscricaoMunicipal, telefone, nome, municipioUf,
 *            codigoIbgeCep, endereco, email, simplesNacional, regimeApuracao.
 * tomador / intermediario: mesmos campos do prestador, sem simplesNacional nem
 *            regimeApuracao. destinatario: igual, sem inscricaoMunicipal.
 *            Quando não identificado, o bloco tem só ["mensagem" => "..."].
 * servico: codigoTributacao, codigoNbs, localPrestacao, descricaoCodigo,
 *          descricaoServico.
 * issqn: ["mensagem" => "..."] quando a operação não é sujeita ao ISSQN;
 *        senão linhas 1..4 (a 2 e a 3 somem quando todos os seus campos estão
 *        sem informação no XML):
 *        1: tipoTributacao, municipioIncidencia
 *        2: regimeEspecial, tipoImunidade, suspensao, numeroProcesso
 *        3: beneficioMunicipal, calculoBeneficio, totalDeducoes,
 *           descontoIncondicionado
 *        4: baseCalculo, aliquotaAplicada, retencao, issqnApurado
 * tributacaoFederal: linhas 1..2 (a 2 só com competência ≤ 2026-12-31):
 *        1: irrf, contribuicaoPrevidenciaria, contribuicoesSociais
 *        2: pis, cofins, descricaoRetPisCofins
 * ibsCbs: linhas 1..4 (sem IBSCBS no XML, tudo "-" e o bloco continua):
 *        1: cst, indicadorOperacao
 *        2: exclusoes, baseCalculo, reducoesAliquota, aliquotaIbs
 *        3: aliquotaEfetivaMun, valorMun, aliquotaEfetivaUf, valorUf
 *        4: valorTotalIbs, aliquotaCbs, aliquotaEfetivaCbs, valorTotalCbs
 * valorTotal:
 *        1: valorOperacao, descontoIncondicionado, descontoCondicionado
 *        2: totalRetencoes, valorLiquido, totalIbsCbs, valorLiquidoTotal
 * informacoesComplementares: itens, totaisTributos.
 * marcaDagua: null, "CANCELADA" ou "SUBSTITUÍDA".
 */
class DanfseDados
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    /** @var array */
    private $dados;

    /**
     * @param string $xml XML da NFS-e (raiz <NFSe>)
     * @param array{cancelada?: bool, substituida?: bool} $opcoes
     */
    public function __construct(string $xml, array $opcoes = [])
    {
        $this->dados = self::doXml($xml, $opcoes);
    }

    /** @return array Dados formatados por bloco (ver docblock da classe). */
    public function paraArray(): array
    {
        return $this->dados;
    }

    /**
     * @param string $xml XML da NFS-e (raiz <NFSe>)
     * @param array{cancelada?: bool, substituida?: bool} $opcoes
     * @return array
     */
    public static function doXml(string $xml, array $opcoes = []): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new NfseNacionalException('XML da NFS-e inválido');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('n', self::NS);
        if ($xp->query('/n:NFSe/n:infNFSe')->length === 0) {
            throw new NfseNacionalException('XML da NFS-e sem infNFSe');
        }

        $i = '/n:NFSe/n:infNFSe';
        $d = $i . '/n:DPS/n:infDPS';

        $chave = (string) preg_replace('/^NFS/', '', self::valor(self::text($xp, $i . '/@Id')));
        $cTribNac = self::text($xp, $d . '/n:serv/n:cServ/n:cTribNac');
        $dCompet = self::text($xp, $d . '/n:dCompet');
        $tpAmb = self::text($xp, $d . '/n:tpAmb');

        // ---- Cabeçalho ----
        $municipioCab = null;
        if ($cTribNac === null || substr(preg_replace('/\D/', '', $cTribNac), 0, 2) !== '99') {
            $municipioCab = self::trunca(
                'Município: ' . self::valor(self::text($xp, $i . '/n:xLocEmi'))
                . ' / ' . self::valor(self::text($xp, $i . '/n:emit/n:enderNac/n:UF')),
                37
            );
        }

        // ---- Dados da NFS-e ----
        $nfse = [
            'chave' => $chave,
            'numero' => self::valor(self::text($xp, $i . '/n:nNFSe')),
            'competencia' => self::data(self::text($xp, $d . '/n:dCompet')),
            'dataHoraEmissao' => self::dataHora(self::text($xp, $i . '/n:dhProc')),
            'numeroDps' => self::valor(self::text($xp, $d . '/n:nDPS')),
            'serieDps' => self::valor(self::text($xp, $d . '/n:serie')),
            'dataHoraEmissaoDps' => self::dataHora(self::text($xp, $d . '/n:dhEmi')),
            'emitente' => self::descricao('tpEmit', self::text($xp, $d . '/n:tpEmit')),
            'situacao' => self::trunca(self::descricao('cStat', self::text($xp, $i . '/n:cStat')), 40),
            'finalidade' => self::trunca(self::descricao('finNFSe', self::text($xp, $d . '/n:IBSCBS/n:finNFSe')), 40),
            'urlConsulta' => 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . $chave,
        ];

        // ---- Prestador / Fornecedor (DPS/prest, com fallback em emit) ----
        $prestador = self::pessoa($xp, [$d . '/n:prest', $i . '/n:emit'], true, true);

        // ---- Tomador / Adquirente ----
        $tomador = $xp->query($d . '/n:toma')->length > 0
            ? self::pessoa($xp, [$d . '/n:toma'], true)
            : ['mensagem' => 'TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e'];

        // ---- Destinatário da Operação ----
        if ($xp->query($d . '/n:IBSCBS/n:dest')->length > 0) {
            $destinatario = self::pessoa($xp, [$d . '/n:IBSCBS/n:dest'], false);
        } elseif (self::text($xp, $d . '/n:IBSCBS/n:indDest') === '0') {
            $destinatario = ['mensagem' => 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO'];
        } else {
            $destinatario = ['mensagem' => 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e'];
        }

        // ---- Intermediário da Operação ----
        $intermediario = $xp->query($d . '/n:interm')->length > 0
            ? self::pessoa($xp, [$d . '/n:interm'], true)
            : ['mensagem' => 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e'];

        // ---- Serviço Prestado ----
        $servico = [
            'codigoTributacao' => self::trunca(
                self::codigoTribNac($cTribNac) . ' / ' . self::valor(self::text($xp, $d . '/n:serv/n:cServ/n:cTribMun')),
                14
            ),
            'codigoNbs' => self::nbs(self::text($xp, $d . '/n:serv/n:cServ/n:cNBS')),
            'localPrestacao' => self::trunca(
                self::valor(self::text($xp, $i . '/n:xLocPrestacao'))
                . ' / ' . self::ufDe(self::text($xp, $d . '/n:serv/n:locPrest/n:cLocPrestacao'))
                . ' / ' . (self::text($xp, $d . '/n:serv/n:locPrest/n:cPaisPrestacao') ?? 'BR'),
                42
            ),
            'descricaoCodigo' => self::trunca(self::valor(self::first($xp, [$d . '/n:serv/n:cServ/n:xTribMun', $i . '/n:xTribNac'])), 170),
            'descricaoServico' => self::trunca(self::valor(self::text($xp, $d . '/n:serv/n:cServ/n:xDescServ')), 1300),
        ];

        // ---- Tributação Municipal (ISSQN) ----
        $tribMun = $d . '/n:valores/n:trib/n:tribMun';
        $temTribMun = $xp->query($tribMun)->length > 0;
        $tribISSQN = self::text($xp, $tribMun . '/n:tribISSQN');
        if (!$temTribMun || $tribISSQN === '4') {
            $issqn = ['mensagem' => 'TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN'];
        } else {
            $issqn = [];
            $issqn[1] = [
                'tipoTributacao' => self::descricao('tribISSQN', $tribISSQN),
                'municipioIncidencia' => self::valor(self::text($xp, $i . '/n:xLocIncid'))
                    . ' / ' . self::ufDe(self::text($xp, $i . '/n:cLocIncid'))
                    . ' / ' . (self::text($xp, $tribMun . '/n:cPaisResult') ?? 'BR'),
            ];

            $linha2Brutos = [
                self::text($xp, $d . '/n:prest/n:regTrib/n:regEspTrib'),
                self::text($xp, $tribMun . '/n:tpImunidade'),
                self::text($xp, $tribMun . '/n:exigSusp/n:tpSusp'),
                self::text($xp, $tribMun . '/n:exigSusp/n:nProcesso'),
            ];
            if (self::algum($linha2Brutos)) {
                $issqn[2] = [
                    'regimeEspecial' => self::descricao('regEspTrib', $linha2Brutos[0]),
                    'tipoImunidade' => self::trunca(self::descricao('tpImunidade', $linha2Brutos[1]), 40),
                    'suspensao' => self::descricao('tpSusp', $linha2Brutos[2]),
                    'numeroProcesso' => self::valor($linha2Brutos[3]),
                ];
            }

            $linha3Brutos = [
                self::first($xp, [$d . '/n:valores/n:tpBM', $i . '/n:valores/n:tpBM']),
                self::first($xp, [$d . '/n:valores/n:vCalcBM', $i . '/n:valores/n:vCalcBM', $tribMun . '/n:BM/n:vRedBCBM']),
                self::first($xp, [$d . '/n:valores/n:vDedRed/n:vDR', $i . '/n:valores/n:vDedRed/n:vDR', $d . '/n:valores/n:vCalcDR', $i . '/n:valores/n:vCalcDR']),
                self::first($xp, [$d . '/n:valores/n:vDescCondIncond/n:vDescIncond', $i . '/n:valores/n:vDescCondIncond/n:vDescIncond']),
            ];
            if (self::algum($linha3Brutos)) {
                $issqn[3] = [
                    'beneficioMunicipal' => self::trunca(self::valor($linha3Brutos[0]), 40),
                    'calculoBeneficio' => self::moeda($linha3Brutos[1]),
                    'totalDeducoes' => self::moeda($linha3Brutos[2]),
                    'descontoIncondicionado' => self::moeda($linha3Brutos[3]),
                ];
            }

            $issqn[4] = [
                'baseCalculo' => self::moeda(self::first($xp, [$d . '/n:valores/n:vBC', $i . '/n:valores/n:vBC'])),
                'aliquotaAplicada' => self::percentual(self::first($xp, [$d . '/n:valores/n:pAliqAplic', $i . '/n:valores/n:pAliqAplic'])),
                'retencao' => self::descricao('tpRetISSQN', self::text($xp, $tribMun . '/n:tpRetISSQN')),
                'issqnApurado' => self::moeda(self::first($xp, [$d . '/n:valores/n:vISSQN', $i . '/n:valores/n:vISSQN'])),
            ];
        }

        // ---- Tributação Federal (Exceto CBS) ----
        $tf = $d . '/n:valores/n:trib/n:tribFed';
        $tpRet = self::text($xp, $tf . '/n:piscofins/n:tpRetPisCofins');
        $vRetCSLL = self::text($xp, $tf . '/n:vRetCSLL');
        $vPis = self::text($xp, $tf . '/n:piscofins/n:vPis');
        $vCofins = self::text($xp, $tf . '/n:piscofins/n:vCofins');

        $tributacaoFederal = [];
        $tributacaoFederal[1] = [
            'irrf' => self::moeda(self::text($xp, $tf . '/n:vRetIRRF')),
            'contribuicaoPrevidenciaria' => self::moeda(self::text($xp, $tf . '/n:vRetCP')),
            'contribuicoesSociais' => $tpRet === '1'
                ? self::somaMoeda([$vRetCSLL, $vPis, $vCofins])
                : self::moeda($vRetCSLL),
        ];
        if ($dCompet === null || $dCompet <= '2026-12-31') {
            $tributacaoFederal[2] = [
                'pis' => $tpRet === '1' ? 'R$ 0,00' : self::moeda($vPis),
                'cofins' => $tpRet === '1' ? 'R$ 0,00' : self::moeda($vCofins),
                'descricaoRetPisCofins' => self::descricao('tpRetPisCofins', $tpRet),
            ];
        }

        // ---- Tributação IBS / CBS ----
        $ib = $i . '/n:IBSCBS';
        $dib = $d . '/n:IBSCBS';
        $temIbscbs = $xp->query($ib)->length > 0 || $xp->query($dib)->length > 0;

        $ibsCbs = [];
        $ibsCbs[1] = [
            'cst' => self::valor(self::text($xp, $dib . '/n:valores/n:trib/n:gIBSCBS/n:CST'))
                . ' / ' . self::valor(self::text($xp, $dib . '/n:valores/n:trib/n:gIBSCBS/n:cClassTrib')),
            'indicadorOperacao' => self::trunca(
                self::valor(self::text($xp, $dib . '/n:cIndOp'))
                . ' / ' . self::valor(self::text($xp, $ib . '/n:cLocalidadeIncid'))
                . ' / ' . self::valor(self::text($xp, $ib . '/n:xLocalidadeIncid'))
                . ' / ' . self::ufDe(self::text($xp, $ib . '/n:cLocalidadeIncid')),
                56
            ),
        ];
        $ibsCbs[2] = [
            'exclusoes' => $temIbscbs
                ? self::somaMoeda([
                    self::text($xp, $d . '/n:valores/n:vDescCondIncond/n:vDescIncond'),
                    self::text($xp, $ib . '/n:valores/n:vCalcReeRepRes'),
                    self::text($xp, $i . '/n:valores/n:vISSQN'),
                    $vPis,
                    $vCofins,
                ])
                : '-',
            'baseCalculo' => self::moeda(self::text($xp, $ib . '/n:valores/n:vBC')),
            'reducoesAliquota' => self::percentual(self::text($xp, $ib . '/n:valores/n:uf/n:pRedAliqUF'))
                . ' / ' . self::percentual(self::text($xp, $ib . '/n:valores/n:mun/n:pRedAliqMun'))
                . ' / ' . self::percentual(self::text($xp, $ib . '/n:valores/n:fed/n:pRedAliqCBS')),
            'aliquotaIbs' => self::percentual(self::text($xp, $ib . '/n:valores/n:uf/n:pIBSUF'))
                . ' / ' . self::percentual(self::text($xp, $ib . '/n:valores/n:mun/n:pIBSMun')),
        ];
        $ibsCbs[3] = [
            'aliquotaEfetivaMun' => self::percentual(self::text($xp, $ib . '/n:valores/n:mun/n:pAliqEfetMun')),
            'valorMun' => self::moeda(self::text($xp, $ib . '/n:totCIBS/n:gIBS/n:gIBSMunTot/n:vIBSMun')),
            'aliquotaEfetivaUf' => self::percentual(self::text($xp, $ib . '/n:valores/n:uf/n:pAliqEfetUF')),
            'valorUf' => self::moeda(self::text($xp, $ib . '/n:totCIBS/n:gIBS/n:gIBSUFTot/n:vIBSUF')),
        ];
        $ibsCbs[4] = [
            'valorTotalIbs' => self::moeda(self::text($xp, $ib . '/n:totCIBS/n:gIBS/n:vIBSTot')),
            'aliquotaCbs' => self::percentual(self::text($xp, $ib . '/n:valores/n:fed/n:pCBS')),
            'aliquotaEfetivaCbs' => self::percentual(self::text($xp, $ib . '/n:valores/n:fed/n:pAliqEfetCBS')),
            'valorTotalCbs' => self::moeda(self::text($xp, $ib . '/n:totCIBS/n:gCBS/n:vCBS')),
        ];

        // ---- Valor Total da NFS-e ----
        $vIBSTot = self::text($xp, $ib . '/n:totCIBS/n:gIBS/n:vIBSTot');
        $vCBS = self::text($xp, $ib . '/n:totCIBS/n:gCBS/n:vCBS');
        $valorTotal = [];
        $valorTotal[1] = [
            'valorOperacao' => self::moeda(self::first($xp, [$d . '/n:valores/n:vServPrest/n:vServ', $i . '/n:valores/n:vServPrest/n:vServ'])),
            'descontoIncondicionado' => self::moeda(self::first($xp, [$d . '/n:valores/n:vDescCondIncond/n:vDescIncond', $i . '/n:valores/n:vDescCondIncond/n:vDescIncond'])),
            'descontoCondicionado' => self::moeda(self::first($xp, [$d . '/n:valores/n:vDescCondIncond/n:vDescCond', $i . '/n:valores/n:vDescCondIncond/n:vDescCond'])),
        ];
        $valorTotal[2] = [
            'totalRetencoes' => self::moeda(self::first($xp, [$d . '/n:valores/n:vTotalRet', $i . '/n:valores/n:vTotalRet'])),
            'valorLiquido' => self::moeda(self::first($xp, [$d . '/n:valores/n:vLiq', $i . '/n:valores/n:vLiq'])),
            'totalIbsCbs' => self::somaMoeda([$vIBSTot, $vCBS]),
            'valorLiquidoTotal' => self::moeda(self::text($xp, $ib . '/n:totCIBS/n:vTotNF')),
        ];

        // ---- Informações Complementares ----
        $itens = [];
        $fontes = [
            ['Inf. Cont.: ', [$d . '/n:serv/n:infoCompl/n:xInfComp']],
            ['NFS-e Subst.: ', [$d . '/n:subst/n:chSubstda']],
            ['Doc. Ref.: ', [$d . '/n:serv/n:infoCompl/n:docRef']],
            ['Cod. Obra: ', [$d . '/n:serv/n:obra/n:cObra']],
            ['Insc. Imob.: ', [$d . '/n:serv/n:obra/n:inscImobFisc']],
            ['Cod. Evt.: ', [$d . '/n:serv/n:atvEvento/n:idAtvEvt']],
            ['Doc. Tec.: ', [$d . '/n:serv/n:infoCompl/n:idDocTec']],
            ['Núm. Ped.: ', [$d . '/n:serv/n:infoCompl/n:gItemPed/n:xPed']],
            ['Item Ped.: ', [$d . '/n:serv/n:infoCompl/n:gItemPed/n:xItemPed']],
            ['Inf. A. T. Mun.: ', [$i . '/n:xOutInf', $d . '/n:xOutInf', $d . '/n:serv/n:infoCompl/n:xOutInf']],
        ];
        foreach ($fontes as $fonte) {
            $valor = self::first($xp, $fonte[1]);
            if ($valor !== null) {
                $itens[] = $fonte[0] . $valor;
            }
        }
        $itensTxt = implode(' | ', $itens);
        if ($itensTxt === '') {
            $itensTxt = '-';
        } elseif (mb_strlen($itensTxt) > 1997) {
            $itensTxt = self::trunca($itensTxt, 1997);
        }

        $tot = $d . '/n:valores/n:trib/n:totTrib';
        $prefixoTotais = 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: ';
        if ($xp->query($tot . '/n:vTotTrib')->length > 0) {
            $linhaTotais = $prefixoTotais
                . 'Federais: ' . self::moeda(self::text($xp, $tot . '/n:vTotTrib/n:vTotTribFed'))
                . '; Estaduais: ' . self::moeda(self::text($xp, $tot . '/n:vTotTrib/n:vTotTribEst'))
                . '; Municipais: ' . self::moeda(self::text($xp, $tot . '/n:vTotTrib/n:vTotTribMun'));
        } elseif ($xp->query($tot . '/n:pTotTrib')->length > 0) {
            $linhaTotais = $prefixoTotais
                . 'Federais: ' . self::percentual(self::text($xp, $tot . '/n:pTotTrib/n:pTotTribFed'))
                . '; Estaduais: ' . self::percentual(self::text($xp, $tot . '/n:pTotTrib/n:pTotTribEst'))
                . '; Municipais: ' . self::percentual(self::text($xp, $tot . '/n:pTotTrib/n:pTotTribMun'));
        } elseif ($xp->query($tot . '/n:pTotTribSN')->length > 0) {
            $linhaTotais = $prefixoTotais . 'Simples Nacional: ' . self::percentual(self::text($xp, $tot . '/n:pTotTribSN'));
        } else {
            $linhaTotais = $prefixoTotais . '-';
        }

        // ---- Marca d'água ----
        if (!empty($opcoes['cancelada'])) {
            $marcaDagua = 'CANCELADA';
        } elseif (!empty($opcoes['substituida'])) {
            $marcaDagua = 'SUBSTITUÍDA';
        } else {
            $marcaDagua = null;
        }

        return [
            'cabecalho' => [
                'municipio' => $municipioCab,
                'ambienteGerador' => self::descricao('ambGer', self::text($xp, $i . '/n:ambGer')),
                'tipoAmbiente' => self::descricao('tpAmb', $tpAmb),
                'aviso' => $tpAmb === '2' ? 'NFS-e SEM VALIDADE JURÍDICA' : null,
            ],
            'nfse' => $nfse,
            'prestador' => $prestador,
            'tomador' => $tomador,
            'destinatario' => $destinatario,
            'intermediario' => $intermediario,
            'servico' => $servico,
            'issqn' => $issqn,
            'tributacaoFederal' => $tributacaoFederal,
            'ibsCbs' => $ibsCbs,
            'valorTotal' => $valorTotal,
            'informacoesComplementares' => [
                'itens' => $itensTxt,
                'totaisTributos' => $linhaTotais,
            ],
            'marcaDagua' => $marcaDagua,
        ];
    }

    /** Texto do nó (aparado); nó ausente ou vazio devolve null. */
    private static function text(DOMXPath $xp, string $caminho): ?string
    {
        $n = $xp->query($caminho)->item(0);
        if ($n === null) {
            return null;
        }
        $v = trim($n->nodeValue);

        return $v === '' ? null : $v;
    }

    /** Primeiro caminho com texto; senão null. */
    private static function first(DOMXPath $xp, array $caminhos): ?string
    {
        foreach ($caminhos as $caminho) {
            $v = self::text($xp, $caminho);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /** Campo ausente vira "-". */
    private static function valor(?string $v): string
    {
        return $v ?? '-';
    }

    /** @param array<int, ?string> $valores */
    private static function algum(array $valores): bool
    {
        foreach ($valores as $v) {
            if ($v !== null) {
                return true;
            }
        }

        return false;
    }

    /** Truncamento da NT: limite = tamanho - 3 caracteres, mais "...". */
    private static function trunca(?string $v, int $limite): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        if (mb_strlen($v) <= $limite) {
            return $v;
        }

        return mb_substr($v, 0, $limite - 3) . '...';
    }

    /** DD/MM/AAAA. */
    private static function data(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) === 1) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $v;
    }

    /** DD/MM/AAAA hh:mm:ss, com a hora como está no XML (sem converter fuso). */
    private static function dataHora(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $v, $m) === 1) {
            return $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6];
        }

        return $v;
    }

    /** R$ 1.234,56. */
    private static function moeda(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }

        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    /** Soma em R$; campo ausente vale zero. Se todos ausentes, "-". */
    private static function somaMoeda(array $valores): string
    {
        $algum = false;
        $soma = 0.0;
        foreach ($valores as $v) {
            if ($v !== null && $v !== '') {
                $algum = true;
                $soma += (float) $v;
            }
        }
        if (!$algum) {
            return '-';
        }

        return self::moeda((string) $soma);
    }

    /** 2,00%. */
    private static function percentual(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }

        return number_format((float) $v, 2, ',', '') . '%';
    }

    /** CNPJ nn.nnn.nnn/nnnn-nn, CPF nnn.nnn.nnn-nn, NIF como está. */
    private static function cnpjCpf(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        $d = preg_replace('/\D/', '', $v);
        if (strlen($d) === 14) {
            return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3)
                . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2);
        }
        if (strlen($d) === 11) {
            return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3)
                . '-' . substr($d, 9, 2);
        }

        return $v;
    }

    /** CEP nn.nnn-nnn. */
    private static function cep(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        $d = preg_replace('/\D/', '', $v);
        if (strlen($d) === 8) {
            return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '-' . substr($d, 5, 3);
        }

        return $v;
    }

    /** Código de tributação nacional nn.nn.nn. */
    private static function codigoTribNac(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        $d = preg_replace('/\D/', '', $v);
        if (strlen($d) >= 6) {
            return substr($d, 0, 2) . '.' . substr($d, 2, 2) . '.' . substr($d, 4, 2);
        }

        return $v;
    }

    /** NBS n.nnnn.nn.nn. */
    private static function nbs(?string $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        $d = preg_replace('/\D/', '', $v);
        if (strlen($d) >= 9) {
            return substr($d, 0, 1) . '.' . substr($d, 1, 4) . '.' . substr($d, 5, 2) . '.' . substr($d, 7, 2);
        }

        return $v;
    }

    /** Junta as partes não vazias com ", "; sem nenhuma, "-". */
    private static function junta(array $partes): string
    {
        $p = [];
        foreach ($partes as $parte) {
            if ($parte !== null && $parte !== '') {
                $p[] = $parte;
            }
        }

        return $p === [] ? '-' : implode(', ', $p);
    }

    /** UF do município pela tabela; sem município, "-". */
    private static function ufDe(?string $cMun): string
    {
        if ($cMun === null || $cMun === '') {
            return '-';
        }
        $m = Tabelas::municipio($cMun);

        return $m !== null ? $m[1] : '-';
    }

    /** Descrição de tabela do Anexo I (código fora da tabela sai como está). */
    private static function descricao(string $tabela, ?string $codigo): string
    {
        return Tabelas::descricao($tabela, $codigo);
    }

    /**
     * Bloco de pessoa (prestador, tomador, destinatário ou intermediário).
     *
     * @param string[] $bases caminhos XPath absolutos, do primeiro com
     *                         precedência ao último
     */
    private static function pessoa(DOMXPath $xp, array $bases, bool $comInscricao, bool $comSimples = false): array
    {
        $bloco = [
            'cnpjCpf' => self::cnpjCpf(self::first($xp, self::caminhos($bases, ['/n:CNPJ', '/n:CPF', '/n:NIF']))),
            'telefone' => self::trunca(self::valor(self::first($xp, self::caminhos($bases, ['/n:fone']))), 20),
            'nome' => self::trunca(self::valor(self::first($xp, self::caminhos($bases, ['/n:xNome']))), 80),
        ];
        if ($comInscricao) {
            $bloco['inscricaoMunicipal'] = self::trunca(self::valor(self::first($xp, self::caminhos($bases, ['/n:IM']))), 15);
        }
        // O endereço sai inteiro de uma origem só, para não misturar o da DPS com o do emitente.
        $basesEnd = $bases;
        foreach ($bases as $base) {
            if ($xp->query($base . '/n:end | ' . $base . '/n:enderNac')->length > 0) {
                $basesEnd = [$base];
                break;
            }
        }
        $bloco['municipioUf'] = self::municipioPessoa($xp, $basesEnd);
        $bloco['codigoIbgeCep'] = self::trunca(self::codigoIbgeCepPessoa($xp, $basesEnd), 21);
        $bloco['endereco'] = self::trunca(self::junta([
            self::first($xp, self::caminhos($basesEnd, ['/n:end/n:xLgr', '/n:enderNac/n:xLgr'])),
            self::first($xp, self::caminhos($basesEnd, ['/n:end/n:nro', '/n:enderNac/n:nro'])),
            self::first($xp, self::caminhos($basesEnd, ['/n:end/n:xCpl', '/n:enderNac/n:xCpl'])),
            self::first($xp, self::caminhos($basesEnd, ['/n:end/n:xBairro', '/n:enderNac/n:xBairro'])),
        ]), 80);
        $bloco['email'] = self::trunca(self::valor(self::first($xp, self::caminhos($bases, ['/n:email']))), 80);
        if ($comSimples) {
            $bloco['simplesNacional'] = self::trunca(self::descricao('opSimpNac', self::first($xp, self::caminhos($bases, ['/n:regTrib/n:opSimpNac']))), 40);
            $bloco['regimeApuracao'] = self::trunca(self::descricao('regApTribSN', self::first($xp, self::caminhos($bases, ['/n:regTrib/n:regApTribSN']))), 80);
        }

        return $bloco;
    }

    /** @param string[] $bases */
    private static function municipioPessoa(DOMXPath $xp, array $bases): string
    {
        $xCidade = self::first($xp, self::caminhos($bases, ['/n:end/n:endExt/n:xCidade']));
        if ($xCidade !== null) {
            return self::trunca($xCidade, 37);
        }
        $cMun = self::first($xp, self::caminhos($bases, ['/n:end/n:endNac/n:cMun', '/n:enderNac/n:cMun']));
        if ($cMun === null) {
            return '-';
        }
        $m = Tabelas::municipio($cMun);
        if ($m !== null) {
            return self::trunca($m[0] . ' / ' . $m[1], 37);
        }
        $uf = self::first($xp, self::caminhos($bases, ['/n:enderNac/n:UF']));

        return self::trunca($cMun . ' / ' . self::valor($uf), 37);
    }

    /** @param string[] $bases */
    private static function codigoIbgeCepPessoa(DOMXPath $xp, array $bases): string
    {
        $cEndPost = self::first($xp, self::caminhos($bases, ['/n:end/n:endExt/n:cEndPost']));
        if ($cEndPost !== null) {
            return $cEndPost;
        }
        $cMun = self::first($xp, self::caminhos($bases, ['/n:end/n:endNac/n:cMun', '/n:enderNac/n:cMun']));
        $cep = self::first($xp, self::caminhos($bases, ['/n:end/n:endNac/n:CEP', '/n:enderNac/n:CEP']));

        return self::valor($cMun) . ' / ' . self::cep($cep);
    }

    /**
     * Combina bases com caminhos relativos, na ordem dada.
     *
     * @param string[] $bases
     * @param string[] $relativos
     * @return string[]
     */
    private static function caminhos(array $bases, array $relativos): array
    {
        $r = [];
        foreach ($bases as $base) {
            foreach ($relativos as $relativo) {
                $r[] = $base . $relativo;
            }
        }

        return $r;
    }
}
