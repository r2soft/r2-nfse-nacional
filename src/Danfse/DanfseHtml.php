<?php

namespace R2soft\NfseNacional\Danfse;

/**
 * HTML + CSS do DANFSe v2.0 (NT SE/CGNFS-e nº 008/2026) para o dompdf.
 *
 * Recebe o array de DanfseDados::doXml() mais $dados['imagens'] = ['logo' => ...,
 * 'qr' => ...] (data URIs PNG montadas por DanfseGerador) e devolve o HTML
 * completo de uma página A4 retrato em Helvetica: corpo com borda de 1pt,
 * separadores de 0,5pt, grade de 4 colunas de 25% (label em cima, valor
 * embaixo), sombreamento #f2f2f2 onde a NT manda e marca d'água diagonal.
 * Todo texto é escapado com htmlspecialchars; não há recursos remotos,
 * flexbox nem grid.
 *
 * @see docs/danfse.md (seção "Leiaute")
 */
class DanfseHtml
{
    private const CSS = <<<'CSS'
@page { margin: 0.3cm; }
body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 7pt; color: #000000; }
.corpo { border: 1pt solid #000000; }
table.bloco { width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: -0.5pt; }
table.bloco td { border: 0.5pt solid #000000; padding: 1pt 2pt; vertical-align: top; }
table.bloco tr.ultima td { border-bottom: none; }
table.cabecalho { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0; }
table.cabecalho td { border: none; border-bottom: 0.5pt solid #000000; background: #f2f2f2; padding: 1pt 2pt; vertical-align: middle; }
.titulo { background: #f2f2f2; }
.titulo-texto { font-size: 7pt; font-weight: bold; }
.sombreado { background: #f2f2f2; }
.label { font-size: 6pt; font-weight: bold; }
.label-nfse { font-size: 7pt; font-weight: bold; }
.valor { font-size: 7pt; }
.qr-texto { font-size: 6pt; }
.celula { min-height: 0.54cm; }
.celula-alta { min-height: 0.58cm; }
.celula-baixa { min-height: 0.32cm; }
.titulo-cab { font-size: 9pt; font-weight: bold; }
.mun-cab { font-size: 8pt; }
.info-cab { font-size: 6pt; }
.aviso-cab { font-size: 9pt; font-weight: bold; color: #ff0000; }
.marca-dagua { position: fixed; top: 45%; left: 0; width: 100%; text-align: center; font-size: 80pt; color: #a6a6a6; transform: rotate(-20deg); }
CSS;

    /**
     * @param array $dados Array de DanfseDados::doXml() mais
     *                     $dados['imagens'] = ['logo' => data URI, 'qr' => data URI]
     * @return string HTML completo (uma página)
     */
    public static function render(array $dados): string
    {
        $html = '<!DOCTYPE html>' . "\n"
            . '<html>' . "\n"
            . '<head><meta charset="UTF-8"><style>' . self::CSS . '</style></head>' . "\n"
            . '<body>' . "\n"
            . '<div class="corpo">' . "\n"
            . self::cabecalho($dados['cabecalho'], $dados['imagens']['logo'])
            . self::blocoNfse($dados['nfse'], $dados['imagens']['qr'])
            . self::blocoPessoa('PRESTADOR / FORNECEDOR', $dados['prestador'], true, true)
            . self::blocoPessoa('TOMADOR / ADQUIRENTE', $dados['tomador'], true)
            . self::blocoPessoa('DESTINATÁRIO DA OPERAÇÃO', $dados['destinatario'], false)
            . self::blocoPessoa('INTERMEDIÁRIO DA OPERAÇÃO', $dados['intermediario'], true)
            . self::blocoServico($dados['servico'])
            . self::blocoIssqn($dados['issqn'])
            . self::blocoTributacaoFederal($dados['tributacaoFederal'])
            . self::blocoIbsCbs($dados['ibsCbs'])
            . self::blocoValorTotal($dados['valorTotal'])
            . self::blocoInformacoesComplementares($dados['informacoesComplementares'])
            . '</div>' . "\n";
        if ($dados['marcaDagua'] !== null) {
            $html .= '<div class="marca-dagua">' . self::e($dados['marcaDagua']) . '</div>' . "\n";
        }

        return $html . '</body>' . "\n" . '</html>';
    }

    /** Escape de todo texto para HTML. */
    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Célula com o label em cima e o valor embaixo.
     *
     * @param array{colspan?: int, sombreado?: bool, labelAlta?: bool,
     *              semLabel?: bool, alta?: bool, baixa?: bool, valorMin?: string} $opcoes
     */
    private static function celula(string $label, string $valor, array $opcoes = []): string
    {
        $html = '<td';
        if (!empty($opcoes['colspan'])) {
            $html .= ' colspan="' . (int) $opcoes['colspan'] . '"';
        }
        if (!empty($opcoes['sombreado'])) {
            $html .= ' class="sombreado"';
        }
        $altura = !empty($opcoes['alta']) ? 'celula-alta' : (!empty($opcoes['baixa']) ? 'celula-baixa' : 'celula');
        $html .= '><div class="' . $altura . '">';
        if (empty($opcoes['semLabel'])) {
            $classeLabel = !empty($opcoes['labelAlta']) ? 'label-nfse' : 'label';
            $html .= '<div class="' . $classeLabel . '">' . self::e($label) . '</div>';
        }
        $estilo = '';
        if (!empty($opcoes['valorMin'])) {
            $estilo = ' style="min-height:' . $opcoes['valorMin'] . '"';
        }
        $html .= '<div class="valor"' . $estilo . '>' . self::e($valor) . '</div>';

        return $html . '</div></td>';
    }

    /** 1ª célula de um bloco: título sombreado, 7pt negrito caixa alta. */
    private static function titulo(string $titulo, int $colspan = 1, bool $baixa = false): string
    {
        $html = '<td class="titulo"';
        if ($colspan > 1) {
            $html .= ' colspan="' . $colspan . '"';
        }

        return $html . '><div class="titulo-texto ' . ($baixa ? 'celula-baixa' : 'celula') . '">'
            . self::e($titulo) . '</div></td>';
    }

    /** Cabeçalho: logo à esquerda, títulos ao centro, município/ambientes à direita. */
    private static function cabecalho(array $c, string $logo): string
    {
        $html = '<table class="cabecalho"><tr>'
            . '<td style="width: 4.4cm;"><img src="' . self::e($logo) . '" style="width: 4.00cm; height: 0.85cm;" alt=""></td>'
            . '<td style="text-align: center;">'
            . '<div class="titulo-cab">DANFSe v2.0</div>'
            . '<div class="titulo-cab">Documento Auxiliar da NFS-e</div>';
        if ($c['aviso'] !== null) {
            $html .= '<div class="aviso-cab">' . self::e($c['aviso']) . '</div>';
        }
        $html .= '</td><td style="width: 5.6cm;">';
        if ($c['municipio'] !== null) {
            $html .= '<div class="mun-cab">' . self::e($c['municipio']) . '</div>';
        }
        $html .= '<div class="info-cab">Ambiente Gerador: ' . self::e($c['ambienteGerador']) . '</div>'
            . '<div class="info-cab">Tipo de Ambiente: ' . self::e($c['tipoAmbiente']) . '</div>'
            . '</td></tr></table>';

        return $html;
    }

    /** Dados da NFS-e: 3 colunas de campos à esquerda e o QR Code à direita. */
    private static function blocoNfse(array $n, string $qr): string
    {
        $alta = ['labelAlta' => true, 'alta' => true];
        $celulaQr = '<td rowspan="4" style="text-align: center;">'
            . '<img src="' . self::e($qr) . '" style="width: 2.0cm; height: 2.0cm;"'
            . ' title="' . self::e($n['urlConsulta']) . '" alt="">'
            . '<div class="qr-texto">A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e</div>'
            . '</td>';

        return '<table class="bloco">'
            . '<tr>'
            . self::celula('CHAVE DE ACESSO DA NFS-E', $n['chave'], $alta + ['colspan' => 3])
            . $celulaQr . '</tr>'
            . '<tr>'
            . self::celula('NÚMERO DA NFS-E', $n['numero'], $alta)
            . self::celula('COMPETÊNCIA DA NFS-E', $n['competencia'], $alta)
            . self::celula('DATA E HORA DA EMISSÃO DA NFS-E', $n['dataHoraEmissao'], $alta) . '</tr>'
            . '<tr>'
            . self::celula('NÚMERO DA DPS', $n['numeroDps'], $alta)
            . self::celula('SÉRIE DA DPS', $n['serieDps'], $alta)
            . self::celula('DATA E HORA DA EMISSÃO DA DPS', $n['dataHoraEmissaoDps'], $alta) . '</tr>'
            . '<tr>'
            . self::celula('EMITENTE DA NFS-E', $n['emitente'], $alta + ['sombreado' => true])
            . self::celula('SITUAÇÃO DA NFS-E', $n['situacao'], $alta)
            . self::celula('FINALIDADE', $n['finalidade'], $alta) . '</tr>'
            . '</table>';
    }

    /**
     * Prestador, tomador, destinatário ou intermediário.
     * Com ['mensagem' => ...], o bloco vira uma linha só.
     */
    private static function blocoPessoa(string $tituloBloco, array $p, bool $comInscricao, bool $comSimples = false): string
    {
        if (isset($p['mensagem'])) {
            return '<table class="bloco"><tr><td class="titulo" colspan="4">'
                . '<div class="titulo-texto celula-baixa">' . self::e($p['mensagem']) . '</div>'
                . '</td></tr></table>';
        }
        $html = '<table class="bloco">'
            . '<tr>' . self::titulo($tituloBloco)
            . self::celula('CNPJ / CPF / NIF', $p['cnpjCpf']);
        $html .= $comInscricao
            ? self::celula('Indicador Municipal (Inscrição)', $p['inscricaoMunicipal'])
            : '<td><div class="celula"></div></td>';
        $html .= self::celula('Telefone', $p['telefone']) . '</tr>'
            . '<tr>'
            . self::celula('Nome / Nome Empresarial', $p['nome'], ['colspan' => 2])
            . self::celula('Município / Sigla UF', $p['municipioUf'])
            . self::celula('Código IBGE / CEP', $p['codigoIbgeCep']) . '</tr>'
            . '<tr>'
            . self::celula('Endereço', $p['endereco'], ['colspan' => 2])
            . self::celula('E-mail', $p['email'], ['colspan' => 2]) . '</tr>';
        if ($comSimples) {
            $html .= '<tr>'
                . self::celula('Simples Nacional na Data de Competência', $p['simplesNacional'])
                . self::celula('Regime de Apuração Tributária pelo SN', $p['regimeApuracao'], ['colspan' => 3]) . '</tr>';
        }

        return $html . '</table>';
    }

    /** Serviço Prestado: Descrição do Serviço com altura mínima de 3,0cm. */
    private static function blocoServico(array $s): string
    {
        return '<table class="bloco">'
            . '<tr>' . self::titulo('SERVIÇO PRESTADO')
            . self::celula('Código de Tributação Nacional / Municipal', $s['codigoTributacao'])
            . self::celula('Código da NBS', $s['codigoNbs'])
            . self::celula('Local da Prestação / Sigla UF / País', $s['localPrestacao']) . '</tr>'
            . '<tr>' . self::celula('', $s['descricaoCodigo'], ['colspan' => 4, 'semLabel' => true]) . '</tr>'
            . '<tr>' . self::celula('Descrição do Serviço', $s['descricaoServico'], ['colspan' => 4, 'valorMin' => '3.0cm']) . '</tr>'
            . '</table>';
    }

    /** Tributação Municipal (ISSQN); as linhas 2 e 3 podem estar ausentes. */
    private static function blocoIssqn(array $b): string
    {
        if (isset($b['mensagem'])) {
            return '<table class="bloco"><tr><td class="titulo" colspan="4">'
                . '<div class="titulo-texto celula-baixa">' . self::e($b['mensagem']) . '</div>'
                . '</td></tr></table>';
        }
        $html = '<table class="bloco">'
            . '<tr>' . self::titulo('TRIBUTAÇÃO MUNICIPAL (ISSQN)')
            . self::celula('Tipo de Tributação do ISSQN', $b[1]['tipoTributacao'])
            . self::celula('Município / Sigla UF / País de Incidência do ISSQN', $b[1]['municipioIncidencia'], ['colspan' => 2]) . '</tr>';
        if (isset($b[2])) {
            $html .= '<tr>'
                . self::celula('Regime Especial de Tributação do ISSQN', $b[2]['regimeEspecial'])
                . self::celula('Tipo de Imunidade do ISSQN', $b[2]['tipoImunidade'])
                . self::celula('Suspensão da Exigibilidade do ISSQN', $b[2]['suspensao'])
                . self::celula('Número Processo Suspensão', $b[2]['numeroProcesso']) . '</tr>';
        }
        if (isset($b[3])) {
            $html .= '<tr>'
                . self::celula('Benefício Municipal', $b[3]['beneficioMunicipal'])
                . self::celula('Cálculo do BM', $b[3]['calculoBeneficio'])
                . self::celula('Total Deduções/Reduções', $b[3]['totalDeducoes'])
                . self::celula('Desconto Incondicionado', $b[3]['descontoIncondicionado']) . '</tr>';
        }

        return $html . '<tr>'
            . self::celula('BC ISSQN', $b[4]['baseCalculo'])
            . self::celula('Alíquota Aplicada', $b[4]['aliquotaAplicada'])
            . self::celula('Retenção do ISSQN', $b[4]['retencao'])
            . self::celula('ISSQN Apurado', $b[4]['issqnApurado']) . '</tr>'
            . '</table>';
    }

    /** Tributação Federal (Exceto CBS); a linha 2 pode estar ausente. */
    private static function blocoTributacaoFederal(array $b): string
    {
        $html = '<table class="bloco">'
            . '<tr>' . self::titulo('TRIBUTAÇÃO FEDERAL (EXCETO CBS)')
            . self::celula('IRRF', $b[1]['irrf'])
            . self::celula('Contribuição Previdenciária - Retida', $b[1]['contribuicaoPrevidenciaria'])
            . self::celula('Contribuições Sociais - Retidas', $b[1]['contribuicoesSociais']) . '</tr>';
        if (isset($b[2])) {
            $html .= '<tr>'
                . self::celula('PIS - Débito Apuração Própria', $b[2]['pis'])
                . self::celula('COFINS - Débito Apuração Própria', $b[2]['cofins'])
                . self::celula('Descrição Contrib. Sociais - Retidas', $b[2]['descricaoRetPisCofins'], ['colspan' => 2]) . '</tr>';
        }

        return $html . '</table>';
    }

    /** Tributação IBS / CBS. */
    private static function blocoIbsCbs(array $b): string
    {
        return '<table class="bloco">'
            . '<tr>' . self::titulo('TRIBUTAÇÃO IBS / CBS')
            . self::celula('CST / cClassTrib', $b[1]['cst'])
            . self::celula('Indicador de Operação / Código IBGE / Município / UF', $b[1]['indicadorOperacao'], ['colspan' => 2]) . '</tr>'
            . '<tr>'
            . self::celula('Exclusões e Reduções da Base de Cálculo', $b[2]['exclusoes'])
            . self::celula('Base de Cálculo Após Exclusões e Reduções', $b[2]['baseCalculo'])
            . self::celula('Red. Alíquota IBS / Red. Alíquota CBS', $b[2]['reducoesAliquota'])
            . self::celula('Alíquota IBS UF / IBS Mun', $b[2]['aliquotaIbs']) . '</tr>'
            . '<tr>'
            . self::celula('Alíq. Efetiva Municipal - IBS', $b[3]['aliquotaEfetivaMun'])
            . self::celula('Valor Apurado Municipal - IBS', $b[3]['valorMun'])
            . self::celula('Alíq. Efetiva Estadual - IBS', $b[3]['aliquotaEfetivaUf'])
            . self::celula('Valor Apurado Estadual - IBS', $b[3]['valorUf']) . '</tr>'
            . '<tr>'
            . self::celula('Valor Total Apurado - IBS', $b[4]['valorTotalIbs'])
            . self::celula('Alíquota - CBS', $b[4]['aliquotaCbs'])
            . self::celula('Alíquota Efetiva - CBS', $b[4]['aliquotaEfetivaCbs'])
            . self::celula('Valor Total Apurado - CBS', $b[4]['valorTotalCbs']) . '</tr>'
            . '</table>';
    }

    /** Valor Total da NFS-e; a célula "Valor Líquido da NFS-e + IBS/CBS" é sombreada. */
    private static function blocoValorTotal(array $b): string
    {
        $alta = ['labelAlta' => true, 'alta' => true];

        return '<table class="bloco">'
            . '<tr>' . self::titulo('VALOR TOTAL DA NFS-E')
            . self::celula('VALOR DA OPERAÇÃO / SERVIÇO', $b[1]['valorOperacao'], $alta)
            . self::celula('Desconto Incondicionado', $b[1]['descontoIncondicionado'], ['alta' => true])
            . self::celula('Desconto Condicionado', $b[1]['descontoCondicionado'], ['alta' => true]) . '</tr>'
            . '<tr>'
            . self::celula('Total das Retenções (ISSQN / Federais)', $b[2]['totalRetencoes'], ['alta' => true])
            . self::celula('VALOR LÍQUIDO DA NFS-E', $b[2]['valorLiquido'], $alta)
            . self::celula('Total do IBS/CBS', $b[2]['totalIbsCbs'], ['alta' => true])
            . self::celula('Valor Líquido da NFS-e + IBS/CBS', $b[2]['valorLiquidoTotal'], ['alta' => true, 'sombreado' => true]) . '</tr>'
            . '</table>';
    }

    /** Informações Complementares: itens com 2,0cm de mínimo e os totais em linha própria. */
    private static function blocoInformacoesComplementares(array $ic): string
    {
        return '<table class="bloco">'
            . '<tr>' . self::titulo('INFORMAÇÕES COMPLEMENTARES', 4) . '</tr>'
            . '<tr>' . self::celula('', $ic['itens'], ['colspan' => 4, 'semLabel' => true, 'valorMin' => '2.0cm']) . '</tr>'
            . '<tr class="ultima">' . self::celula('', $ic['totaisTributos'], ['colspan' => 4, 'semLabel' => true]) . '</tr>'
            . '</table>';
    }
}
